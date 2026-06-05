<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — Données</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .tab-bar { display:flex; gap:4px; border-bottom:2px solid var(--border); margin-bottom:20px; }
        .tab { padding:8px 16px; font-size:0.85rem; font-weight:600; cursor:pointer; color:var(--text3); border:none; background:none; border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s; }
        .tab.active { color:var(--blue); border-bottom-color:var(--blue); }
        .tab-panel { display:none; } .tab-panel.active { display:block; }

        .update-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:16px 20px; margin-bottom:16px; }
        .update-card h3 { font-size:0.9rem; font-weight:700; margin-bottom:12px; }
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:10px; margin-bottom:14px; }
        .stat-box { background:var(--surface2); border-radius:var(--radius); padding:10px 12px; text-align:center; }
        .stat-box .val { font-size:1.3rem; font-weight:700; color:var(--blue); }
        .stat-box .lbl { font-size:0.72rem; color:var(--text3); margin-top:2px; }
        .log-output { background:#1a2332; color:#a5f3fc; font-family:monospace; font-size:0.75rem;
                      border-radius:var(--radius); padding:12px; max-height:200px; overflow-y:auto;
                      white-space:pre-wrap; word-break:break-all; margin-top:10px; }
        .status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.75rem; font-weight:700; }
        .status-running { background:#fef9c3; color:#a16207; }
        .status-ok      { background:#dcfce7; color:#166534; }
        .status-error   { background:#fee2e2; color:#991b1b; }
        .status-idle    { background:var(--surface2); color:var(--text3); }
    </style>
    <script>const _P={carte:'/',donnees:'/donnees',requetes:'/requetes',crm:'/crm'};function navTo(p){window.parent?.showPage?window.parent.showPage(p):location.href=_P[p]||'/';}if(window.self!==window.top)document.addEventListener('DOMContentLoaded',()=>{const n=document.querySelector('nav');if(n)n.style.display='none';document.body.style.paddingTop='0';});</script>
</head>
<body>

<nav>
    <span class="nav-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/></svg>
        RSig
    </span>
    <a href="#" onclick="navTo('carte');return false">Carte</a>
    <a href="#" class="active">Données</a>
    <a href="#" onclick="navTo('requetes');return false">Requêtes</a>
    <a href="#" onclick="navTo('crm');return false">CRM</a>
</nav>

<div class="page-content">
    <h1>Données</h1>

    <!-- Onglets -->
    <div class="tab-bar">
        <button class="tab active" data-tab="explorer">Explorateur de tables</button>
        <button class="tab" data-tab="ta">Mise à jour Taxe d'Aménagement</button>
    </div>

    <!-- ═══ ONGLET TA ══════════════════════════════════════ -->
    <div class="tab-panel" id="tab-ta">

        <div class="update-card">
            <h3>État de la base TA</h3>
            <div class="stat-grid" id="ta-stats">
                <div class="stat-box"><div class="val" id="ta-communes">…</div><div class="lbl">Communes</div></div>
                <div class="stat-box"><div class="val" id="ta-dep">…</div><div class="lbl">Avec taux dép.</div></div>
                <div class="stat-box"><div class="val" id="ta-reg">…</div><div class="lbl">Avec taux rég. IDF</div></div>
                <div class="stat-box"><div class="val" id="ta-date">…</div><div class="lbl">Date effet max</div></div>
            </div>
            <div style="font-size:0.82rem;color:var(--text2)">
                Dernier run :
                <span id="ta-last-status" class="status-badge status-idle">jamais</span>
                <span id="ta-last-date" style="color:var(--text3);margin-left:6px"></span>
                <span id="ta-last-msg" style="color:var(--text3);margin-left:6px"></span>
            </div>
        </div>

        <div class="update-card">
            <h3>Lancer une mise à jour</h3>
            <p style="font-size:0.82rem;color:var(--text2);margin-bottom:12px">
                Récupère les taux de taxe d'aménagement depuis l'API DGFIP
                (<a href="https://data.economie.gouv.fr/explore/dataset/delta_deliberation_tam_17_01_23" target="_blank">data.economie.gouv.fr</a>)
                et met à jour la base de données. Seules les communes avec une date d'effet plus récente sont modifiées.
            </p>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button class="btn" id="btn-ta-update" onclick="lancerMajTA()">
                    Lancer la mise à jour
                </button>
                <span id="ta-run-status" style="font-size:0.82rem;color:var(--text3)"></span>
            </div>
            <div id="ta-log" class="log-output" style="display:none"></div>
        </div>

        <div class="update-card">
            <h3>Valeurs forfaitaires (CGI art. 1635 quater B)</h3>
            <p style="font-size:0.82rem;color:var(--text2);margin-bottom:10px">
                Ces valeurs sont codées en dur dans le script et mises à jour manuellement chaque année.
            </p>
            <div id="ta-forfait-table"></div>
        </div>

    </div>

    <!-- ═══ ONGLET EXPLORATEUR ════════════════════════════ -->
    <div class="tab-panel active" id="tab-explorer">

    <?php if (!$connected): ?>
    <div class="alert alert-error">
        <strong>Base PostgreSQL non disponible</strong> (localhost:5432 — mabase)<br>
        Vérifiez que PostgreSQL est démarré et que l'extension <code>pdo_pgsql</code> est chargée par Apache.<br>
        <a href="/api/db-check" target="_blank" style="color:inherit;text-decoration:underline">→ Diagnostic PHP/PDO</a>
    </div>
    <?php else: ?>

    <?php if (empty($tables)): ?>
    <div class="alert alert-warn">Aucune table trouvée dans le schéma public.</div>
    <?php else: ?>

    <form method="GET" action="/donnees" class="table-select-form">
        <label for="table-select">Table :</label>
        <select id="table-select" name="table" onchange="this.form.submit()">
            <?php foreach ($tables as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>" <?= $t === $table ? 'selected' : '' ?>>
                <?= htmlspecialchars($t) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>

    <?php if (empty($rows)): ?>
    <div class="alert alert-info">Table vide ou inaccessible.</div>
    <?php else: ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <?php foreach ($cols as $col): ?>
                    <th><?= htmlspecialchars($col) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $cell): ?>
                    <td><?= htmlspecialchars((string)$cell) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="row-count"><?= count($rows) ?> enregistrement(s) — limite 100</p>

    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

    </div><!-- /tab-explorer -->
</div>

<script>
// ── Tabs ─────────────────────────────────────────────────
document.querySelectorAll('.tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        if (btn.dataset.tab === 'ta') loadTaStatus();
    });
});

// ── Statut TA ─────────────────────────────────────────────
let taPolling = null;

function loadTaStatus() {
    fetch('/api/ta/update/status')
        .then(r => r.json())
        .then(d => {
            document.getElementById('ta-communes').textContent = d.communes?.toLocaleString('fr-FR') || '0';
            document.getElementById('ta-dep').textContent      = d.avec_dep?.toLocaleString('fr-FR') || '0';
            document.getElementById('ta-reg').textContent      = d.avec_reg?.toLocaleString('fr-FR') || '0';
            document.getElementById('ta-date').textContent     = d.date_effet_max ? d.date_effet_max.slice(0,10) : '–';

            const run = d.last_run;
            const badge = document.getElementById('ta-last-status');
            const dateEl = document.getElementById('ta-last-date');
            const msgEl  = document.getElementById('ta-last-msg');

            if (!run) {
                badge.className = 'status-badge status-idle';
                badge.textContent = 'jamais lancé';
            } else {
                badge.className = 'status-badge status-' + run.status;
                badge.textContent = run.status === 'running' ? 'En cours…' : run.status === 'ok' ? 'Succès' : 'Erreur';
                dateEl.textContent = run.finished_at ? new Date(run.finished_at).toLocaleString('fr-FR') : 'en cours…';
                if (run.communes_updated) msgEl.textContent = run.communes_updated.toLocaleString('fr-FR') + ' communes mises à jour';
                else msgEl.textContent = run.message ? run.message.slice(0, 80) : '';

                if (run.status === 'running') {
                    document.getElementById('btn-ta-update').disabled = true;
                    document.getElementById('ta-run-status').textContent = 'Mise à jour en cours…';
                    if (!taPolling) taPolling = setInterval(loadTaStatus, 4000);
                } else {
                    document.getElementById('btn-ta-update').disabled = false;
                    document.getElementById('ta-run-status').textContent = '';
                    if (taPolling) { clearInterval(taPolling); taPolling = null; }
                }
            }
        })
        .catch(() => {});

    // Valeurs forfaitaires
    fetch('/api/ta/forfaitaires')
        .then(r => r.json())
        .then(d => {
            const rows = (d.forfaitaires || []);
            if (!rows.length) return;
            const millesimes = [...new Set(rows.map(r => r.annee))].sort().reverse();
            let html = '<div class="result-table-wrap"><table><thead><tr><th>Type</th>' +
                millesimes.map(m => `<th>${m} IDF</th><th>${m} France</th>`).join('') +
                '</tr></thead><tbody>';
            const types = [...new Set(rows.map(r => r.type_local))];
            types.forEach(t => {
                html += `<tr><td>${escHtml(t)}</td>`;
                millesimes.forEach(m => {
                    const idf = rows.find(r => r.annee == m && r.zone === 'IDF'    && r.type_local === t);
                    const fr  = rows.find(r => r.annee == m && r.zone === 'FRANCE' && r.type_local === t);
                    html += `<td style="text-align:right;color:var(--blue)">${idf ? (+idf.valeur).toLocaleString('fr-FR') + ' €/m²' : '–'}</td>`;
                    html += `<td style="text-align:right;color:var(--blue)">${fr  ? (+fr.valeur).toLocaleString('fr-FR')  + ' €/m²' : '–'}</td>`;
                });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            document.getElementById('ta-forfait-table').innerHTML = html;
        })
        .catch(() => {});
}

function lancerMajTA() {
    const btn = document.getElementById('btn-ta-update');
    const statusEl = document.getElementById('ta-run-status');
    const logEl = document.getElementById('ta-log');
    btn.disabled = true;
    statusEl.textContent = 'Lancement…';
    logEl.style.display = 'none';

    fetch('/api/ta/update', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.error) {
                statusEl.textContent = 'Erreur : ' + d.error;
                btn.disabled = false;
                return;
            }
            if (d.status === 'already_running') {
                statusEl.textContent = 'Déjà en cours (log #' + d.log_id + ')';
                btn.disabled = false;
                return;
            }
            statusEl.textContent = 'Démarré (log #' + d.log_id + ')…';
            logEl.style.display = 'block';
            logEl.textContent = 'Initialisation…';
            // Polling
            if (taPolling) clearInterval(taPolling);
            taPolling = setInterval(() => {
                loadTaStatus();
                // Lire le fichier log si accessible
                fetch('/api/ta/update/status')
                    .then(r => r.json())
                    .then(s => {
                        if (s.last_run?.message) logEl.textContent = s.last_run.message;
                        if (s.last_run?.status !== 'running') {
                            logEl.textContent = s.last_run?.status === 'ok'
                                ? '✓ Terminé — ' + (s.last_run.communes_updated || 0) + ' communes mises à jour'
                                : '✗ Erreur : ' + (s.last_run?.message || '');
                        }
                    });
            }, 4000);
        })
        .catch(e => {
            statusEl.textContent = 'Erreur réseau : ' + e.message;
            btn.disabled = false;
        });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>
</body>
</html>
