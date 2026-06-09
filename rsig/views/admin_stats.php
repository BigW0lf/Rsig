<?php
/**
 * admin_stats.php — Statistiques d'utilisation (admin seulement)
 * Page autonome (non rendue par Flight), accès direct.
 */

requireAdmin();

$db = getDb();

// ── Requêtes stats ─────────────────────────────────────────────────────────
$stats = [
    'today'   => 0,
    'week'    => 0,
    'month'   => 0,
    'total'   => 0,
    'uniq_month' => 0,
];
$perPage     = [];
$lastVisits  = [];
$layersUsage = [];

if ($db) {
    try {
        // Créer la table si elle n'existe pas encore
        $db->exec("CREATE TABLE IF NOT EXISTS site_visits (
            id          SERIAL PRIMARY KEY,
            visited_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
            page        TEXT NOT NULL,
            user_name   TEXT,
            user_email  TEXT,
            ip          TEXT,
            layers_used TEXT
        )");

        // Totaux
        $row = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE visited_at >= CURRENT_DATE)                              AS today,
                COUNT(*) FILTER (WHERE visited_at >= date_trunc('week',  CURRENT_TIMESTAMP))    AS week,
                COUNT(*) FILTER (WHERE visited_at >= date_trunc('month', CURRENT_TIMESTAMP))    AS month,
                COUNT(*)                                                                         AS total
            FROM site_visits
        ")->fetch();
        if ($row) {
            $stats['today'] = (int)$row['today'];
            $stats['week']  = (int)$row['week'];
            $stats['month'] = (int)$row['month'];
            $stats['total'] = (int)$row['total'];
        }

        // Utilisateurs uniques ce mois
        $rowU = $db->query("
            SELECT COUNT(DISTINCT user_email) AS uniq
            FROM site_visits
            WHERE visited_at >= date_trunc('month', CURRENT_TIMESTAMP)
        ")->fetch();
        if ($rowU) $stats['uniq_month'] = (int)$rowU['uniq'];

        // Visites par page
        $stmt = $db->query("
            SELECT page, COUNT(*) AS cnt
            FROM site_visits
            GROUP BY page
            ORDER BY cnt DESC
            LIMIT 20
        ");
        $perPage = $stmt->fetchAll();

        // 20 dernières visites
        $stmt = $db->query("
            SELECT visited_at, page, user_name, user_email, ip, layers_used
            FROM site_visits
            ORDER BY visited_at DESC
            LIMIT 20
        ");
        $lastVisits = $stmt->fetchAll();

        // Couches les plus activées (layers_used est TEXT, pas JSON)
        $stmt = $db->query("
            SELECT layer_name, COUNT(*) AS cnt
            FROM site_visits,
                 json_array_elements_text(
                     CASE WHEN layers_used IS NULL OR layers_used = 'null' OR layers_used = ''
                          THEN '[]'::json
                          ELSE layers_used::json END
                 ) AS layer_name
            GROUP BY layer_name
            ORDER BY cnt DESC
            LIMIT 15
        ");
        $layersUsage = $stmt->fetchAll();

    } catch (PDOException $e) {
        $dbError = $e->getMessage();
    }
}

// ── Helpers affichage ──────────────────────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function anonymizeIp(string $ip): string {
    // IPv4 : masquer le dernier octet
    if (preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', $ip, $m)) {
        return $m[1] . '.***';
    }
    // IPv6 : garder seulement les 3 premiers groupes
    $parts = explode(':', $ip);
    if (count($parts) >= 3) {
        return implode(':', array_slice($parts, 0, 3)) . ':***';
    }
    return substr($ip, 0, 6) . '***';
}

$maxPage = $perPage ? max(array_column($perPage, 'cnt')) : 1;
$maxLayer = $layersUsage ? max(array_column($layersUsage, 'cnt')) : 1;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — Statistiques admin</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        body {
            background: var(--bg);
            color: var(--text);
            font-size: 13px;
        }

        /* ── Top bar ─────────────────────────── */
        .admin-nav {
            background: var(--blue);
            padding: 0 20px;
            height: 48px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 6px rgba(0,0,0,.25);
        }
        .admin-nav-title {
            color: #fff;
            font-weight: 700;
            font-size: 15px;
            letter-spacing: .3px;
        }
        .admin-nav-sub {
            color: rgba(255,255,255,.6);
            font-size: 12px;
        }
        .admin-nav a {
            color: rgba(255,255,255,.75);
            font-size: 12px;
            text-decoration: none;
            margin-left: auto;
            padding: 4px 10px;
            border-radius: 4px;
            border: 1px solid rgba(255,255,255,.2);
            transition: background .15s;
        }
        .admin-nav a:hover { background: rgba(255,255,255,.15); color: #fff; }

        /* ── Content ─────────────────────────── */
        .admin-wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 28px 20px 48px;
        }

        /* ── KPI cards ───────────────────────── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 14px;
            margin-bottom: 28px;
        }
        .kpi-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px 18px;
            box-shadow: var(--shadow-sm);
        }
        .kpi-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text3);
            margin-bottom: 6px;
        }
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--blue);
            line-height: 1;
        }
        .kpi-card.accent .kpi-value { color: var(--accent); }
        .kpi-card.green  .kpi-value { color: var(--green); }

        /* ── Section titles ──────────────────── */
        .section-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--blue);
            border-bottom: 2px solid var(--blue);
            padding-bottom: 6px;
            margin: 28px 0 14px;
        }

        /* ── Bar chart ───────────────────────── */
        .bar-chart { display: flex; flex-direction: column; gap: 6px; }
        .bar-row {
            display: grid;
            grid-template-columns: 160px 1fr 40px;
            align-items: center;
            gap: 10px;
        }
        .bar-label {
            font-size: 11px;
            color: var(--text2);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bar-track {
            height: 14px;
            background: var(--surface2);
            border-radius: 7px;
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            background: var(--blue-light);
            border-radius: 7px;
            transition: width .4s ease;
            min-width: 2px;
        }
        .bar-fill.orange { background: #f59e0b; }
        .bar-count {
            font-size: 11px;
            font-weight: 700;
            color: var(--text2);
            text-align: right;
        }

        /* ── Table ───────────────────────────── */
        .visits-table-wrap {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
        }
        .visits-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .visits-table thead { background: var(--blue); }
        .visits-table th {
            padding: 8px 12px;
            text-align: left;
            color: #fff;
            font-weight: 600;
            white-space: nowrap;
            border-right: 1px solid rgba(255,255,255,.1);
        }
        .visits-table th:last-child { border-right: none; }
        .visits-table td {
            padding: 6px 12px;
            border-bottom: 1px solid var(--border2);
            color: var(--text);
            white-space: nowrap;
        }
        .visits-table tbody tr:hover td { background: var(--blue-hover); }
        .visits-table td.page { color: var(--blue-light); font-weight: 500; }
        .visits-table td.ts   { color: var(--text3); font-size: 11px; }
        .visits-table td.ip   { color: var(--text3); font-size: 11px; font-family: monospace; }

        /* ── Refresh info ────────────────────── */
        .refresh-note {
            font-size: 11px;
            color: var(--text3);
            text-align: right;
            margin-top: 24px;
        }
        #countdown { font-weight: 700; color: var(--blue-light); }

        /* ── Error banner ────────────────────── */
        .db-error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 12px;
        }
    </style>
</head>
<body>

<div class="admin-nav">
    <span class="admin-nav-title">RSig</span>
    <span class="admin-nav-sub">Statistiques d'utilisation</span>
    <a href="/">← Carte</a>
</div>

<div class="admin-wrap">

    <?php if (isset($dbError)): ?>
    <div class="db-error">
        <strong>Erreur base de données :</strong> <?= h($dbError) ?><br>
        <small>La table <code>site_visits</code> existe-t-elle ?</small>
    </div>
    <?php endif; ?>

    <!-- ── KPIs ─────────────────────────────────────── -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-label">Aujourd'hui</div>
            <div class="kpi-value"><?= $stats['today'] ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Cette semaine</div>
            <div class="kpi-value"><?= $stats['week'] ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Ce mois</div>
            <div class="kpi-value"><?= $stats['month'] ?></div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Total</div>
            <div class="kpi-value"><?= $stats['total'] ?></div>
        </div>
        <div class="kpi-card green">
            <div class="kpi-label">Utilisateurs uniques (mois)</div>
            <div class="kpi-value"><?= $stats['uniq_month'] ?></div>
        </div>
    </div>

    <!-- ── Visites par page ──────────────────────────── -->
    <div class="section-title">Visites par page</div>
    <?php if ($perPage): ?>
    <div class="bar-chart">
        <?php foreach ($perPage as $row): ?>
        <div class="bar-row">
            <span class="bar-label" title="<?= h($row['page']) ?>"><?= h($row['page'] ?: '(racine)') ?></span>
            <div class="bar-track">
                <div class="bar-fill" style="width:<?= round($row['cnt'] / $maxPage * 100) ?>%"></div>
            </div>
            <span class="bar-count"><?= (int)$row['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:var(--text3);font-size:12px">Aucune donnée.</p>
    <?php endif; ?>

    <!-- ── Couches les plus activées ────────────────── -->
    <div class="section-title">Couches les plus activées</div>
    <?php if ($layersUsage): ?>
    <div class="bar-chart">
        <?php foreach ($layersUsage as $row): ?>
        <div class="bar-row">
            <span class="bar-label" title="<?= h($row['layer_name']) ?>"><?= h($row['layer_name']) ?></span>
            <div class="bar-track">
                <div class="bar-fill orange" style="width:<?= round($row['cnt'] / $maxLayer * 100) ?>%"></div>
            </div>
            <span class="bar-count"><?= (int)$row['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p style="color:var(--text3);font-size:12px">Aucune donnée (colonne <code>layers_used</code> vide ou absente).</p>
    <?php endif; ?>

    <!-- ── 20 dernières visites ──────────────────────── -->
    <div class="section-title">20 dernières visites</div>
    <?php if ($lastVisits): ?>
    <div class="visits-table-wrap">
        <table class="visits-table">
            <thead>
                <tr>
                    <th>Horodatage</th>
                    <th>Page</th>
                    <th>Utilisateur</th>
                    <th>IP (anonymisée)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lastVisits as $v): ?>
                <tr>
                    <td class="ts"><?= h($v['visited_at']) ?></td>
                    <td class="page"><?= h($v['page'] ?: '/') ?></td>
                    <td><?= h($v['user_name'] ?? $v['user_email'] ?? '—') ?></td>
                    <td class="ip"><?= h(anonymizeIp($v['ip'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color:var(--text3);font-size:12px">Aucune visite enregistrée.</p>
    <?php endif; ?>

    <div class="refresh-note">
        Rafraîchissement automatique dans <span id="countdown">60</span>s
        — Connecté : <strong><?= h($_SESSION['user_email'] ?? 'local') ?></strong>
        — Généré le <?= date('d/m/Y à H:i:s') ?>
    </div>

</div><!-- /admin-wrap -->

<script>
// Auto-refresh toutes les 60 secondes avec compte à rebours
let remaining = 60;
const cd = document.getElementById('countdown');
const timer = setInterval(() => {
    remaining--;
    if (cd) cd.textContent = remaining;
    if (remaining <= 0) {
        clearInterval(timer);
        location.reload();
    }
}, 1000);
</script>
</body>
</html>
