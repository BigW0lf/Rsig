<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — <?= htmlspecialchars($account['name'] ?? 'Client') ?></title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .client-wrap {
            max-width: 920px;
            margin: 32px auto;
            padding: 0 16px;
        }
        .client-header {
            display: flex;
            align-items: flex-start;
            gap: 20px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .client-avatar {
            width: 56px; height: 56px;
            border-radius: 50%;
            background: var(--blue);
            color: #fff;
            font-size: 22px;
            font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .client-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }
        .client-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 16px;
            margin-top: 8px;
        }
        .client-meta-item {
            font-size: 12px;
            color: var(--text2);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .client-meta-item a { color: var(--blue-light); }
        .client-meta-item a:hover { text-decoration: underline; }
        .client-rtx-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--blue);
            color: #fff;
            border-radius: 4px;
            padding: 2px 9px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: .3px;
        }
        .client-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin-bottom: 16px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .client-section-title {
            padding: 10px 16px;
            background: var(--surface2);
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            font-size: 12px;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .client-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0;
        }
        .client-field {
            padding: 10px 16px;
            border-bottom: 1px solid var(--border2);
        }
        .client-field:last-child { border-bottom: none; }
        .client-field-label {
            font-size: 11px;
            color: var(--text3);
            margin-bottom: 2px;
        }
        .client-field-value {
            font-size: 13px;
            color: var(--text);
            font-weight: 500;
        }
        .client-description {
            padding: 12px 16px;
            font-size: 13px;
            color: var(--text2);
            font-style: italic;
            border-top: 1px solid var(--border2);
        }

        /* Table dossiers */
        .dos-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .dos-table th {
            text-align: left;
            padding: 8px 12px;
            background: var(--surface2);
            color: var(--text3);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: .4px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .dos-table td {
            padding: 8px 12px;
            border-bottom: 1px solid var(--border2);
            color: var(--text);
        }
        .dos-table tr:last-child td { border-bottom: none; }
        .dos-table tr:hover td { background: var(--blue-hover); }
        .etat-plus  { color: #16a34a; font-weight: 700; }
        .etat-moins { color: #dc2626; font-weight: 700; }
        .etat-ind   { color: var(--text3); }
        .phase-tag {
            display: inline-block;
            background: var(--surface2);
            border-radius: 3px;
            padding: 1px 6px;
            font-size: 11px;
            color: var(--text2);
        }
        .dos-nb {
            display: inline-block;
            background: var(--blue);
            color: #fff;
            border-radius: 10px;
            padding: 1px 8px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 6px;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--blue-light);
            font-size: 13px;
            margin-bottom: 16px;
            cursor: pointer;
        }
        .back-btn:hover { text-decoration: underline; }
    </style>
</head>
<body>

<nav>
    <span class="nav-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/></svg>
        RSig
    </span>
    <a href="/">Carte</a>
    <span class="nav-user" style="margin-left:auto"><?= htmlspecialchars($_SESSION['user_name'] ?? '') ?></span>
    <a href="/auth/logout" class="nav-logout" title="Déconnexion" style="color:rgba(255,255,255,.7);margin-left:8px">&#x2715;</a>
</nav>

<div class="client-wrap">

    <a class="back-btn" onclick="history.back()">
        ← Retour
    </a>

    <!-- En-tête client -->
    <div class="client-header">
        <div class="client-avatar"><?= htmlspecialchars(mb_substr($account['name'] ?? '?', 0, 1)) ?></div>
        <div style="flex:1">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <div class="client-name"><?= htmlspecialchars($account['name'] ?? '—') ?></div>
                <?php if ($account['rtx_code']): ?>
                <span class="client-rtx-badge">★ <?= htmlspecialchars($account['rtx_code']) ?></span>
                <?php endif; ?>
            </div>
            <div class="client-meta">
                <?php if ($account['siren']): ?>
                <span class="client-meta-item">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                    SIREN <?= htmlspecialchars($account['siren']) ?>
                </span>
                <?php endif; ?>
                <?php if ($account['telephone']): ?>
                <span class="client-meta-item">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.79 19.79 0 0 1 11.63 19 19.45 19.45 0 0 1 5 12.36 19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/','',$account['telephone'])) ?>"><?= htmlspecialchars($account['telephone']) ?></a>
                </span>
                <?php endif; ?>
                <?php if ($account['email1']): ?>
                <span class="client-meta-item">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <a href="mailto:<?= htmlspecialchars($account['email1']) ?>"><?= htmlspecialchars($account['email1']) ?></a>
                </span>
                <?php endif; ?>
                <?php if ($account['email2'] && $account['email2'] !== $account['email1']): ?>
                <span class="client-meta-item">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    <a href="mailto:<?= htmlspecialchars($account['email2']) ?>"><?= htmlspecialchars($account['email2']) ?></a>
                </span>
                <?php endif; ?>
                <?php if ($account['ville']): ?>
                <span class="client-meta-item">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?= htmlspecialchars(trim(($account['adresse'] ? $account['adresse'].', ' : '') . ($account['code_postal'] ?? '') . ' ' . $account['ville'])) ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Infos complémentaires -->
    <?php if ($account['rcs'] || $account['retrocession'] !== null): ?>
    <div class="client-section">
        <div class="client-section-title">Informations commerciales</div>
        <div class="client-grid">
            <?php if ($account['rcs']): ?>
            <div class="client-field">
                <div class="client-field-label">RCS</div>
                <div class="client-field-value"><?= htmlspecialchars($account['rcs']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($account['retrocession'] !== null): ?>
            <div class="client-field">
                <div class="client-field-label">Taux de rétrocession</div>
                <div class="client-field-value"><?= number_format((float)$account['retrocession'] * 100, 1) ?> %</div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($account['description']): ?>
        <div class="client-description"><?= htmlspecialchars($account['description']) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Contacts -->
    <?php if (!empty($contacts)): ?>
    <div class="client-section">
        <div class="client-section-title">
            Contacts
            <span class="dos-nb"><?= count($contacts) ?></span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:0">
        <?php foreach ($contacts as $c): ?>
        <?php
            $badges = [];
            if ($c['decisionnaire']) $badges[] = '<span style="background:#003189;color:#fff;border-radius:3px;padding:1px 6px;font-size:10px;font-weight:600">Décisionnaire</span>';
            if ($c['topo'])         $badges[] = '<span style="background:#1a7a3c;color:#fff;border-radius:3px;padding:1px 6px;font-size:10px;font-weight:600">Topo</span>';
        ?>
        <div style="padding:12px 16px;border-bottom:1px solid var(--border2);border-right:1px solid var(--border2)">
            <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px">
                <div style="width:32px;height:32px;border-radius:50%;background:var(--blue-light);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <?= htmlspecialchars(mb_substr($c['fullname'] ?? '?', 0, 1)) ?>
                </div>
                <div>
                    <div style="font-weight:600;font-size:13px">
                        <?= htmlspecialchars(trim(($c['civilite'] ? $c['civilite'].' ' : '') . ($c['fullname'] ?? ''))) ?>
                    </div>
                    <?php if ($c['jobtitle']): ?>
                    <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars($c['jobtitle']) ?></div>
                    <?php endif; ?>
                    <?php if ($badges): ?>
                    <div style="display:flex;gap:4px;margin-top:3px"><?= implode('', $badges) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($c['telephone1'] || $c['mobile'] || $c['telephone2']): ?>
            <div style="font-size:12px;color:var(--text2);margin-bottom:2px">
                <?php foreach (['telephone1'=>'Tél', 'mobile'=>'Mobile', 'telephone2'=>'Tél 2'] as $field => $label): ?>
                <?php if ($c[$field]): ?>
                <div>
                    <span style="color:var(--text3)"><?= $label ?> :</span>
                    <a href="tel:<?= htmlspecialchars(preg_replace('/\s+/','',$c[$field])) ?>" style="color:var(--blue-light)"><?= htmlspecialchars($c[$field]) ?></a>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($c['email']): ?>
            <div style="font-size:12px;margin-bottom:2px">
                <a href="mailto:<?= htmlspecialchars($c['email']) ?>" style="color:var(--blue-light)"><?= htmlspecialchars($c['email']) ?></a>
            </div>
            <?php endif; ?>
            <?php if ($c['ville']): ?>
            <div style="font-size:11px;color:var(--text3)"><?= htmlspecialchars(trim(($c['adresse'] ? $c['adresse'].', ' : '') . ($c['code_postal'] ?? '') . ' ' . ($c['ville'] ?? ''))) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Dossiers -->
    <div class="client-section">
        <div class="client-section-title">
            Dossiers
            <span class="dos-nb"><?= count($dossiers) ?></span>
        </div>
        <?php if (!$dossiers): ?>
        <div style="padding:16px;color:var(--text3);font-style:italic">Aucun dossier</div>
        <?php else: ?>
        <div style="overflow-x:auto">
        <table class="dos-table">
            <thead>
                <tr>
                    <th>Numéro</th>
                    <th>Produit</th>
                    <th>Phase</th>
                    <th>État</th>
                    <th>Demande</th>
                    <th>Remise</th>
                    <th>Ville</th>
                    <th>TF (€)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($dossiers as $d): ?>
            <?php
                $etatCls = match($d['etat'] ?? '') { '+' => 'etat-plus', '-' => 'etat-moins', default => 'etat-ind' };
                $tf = isset($d['montant_tf']) && $d['montant_tf'] > 0 ? number_format((float)$d['montant_tf'], 0, ',', ' ') : '—';
            ?>
            <tr>
                <td style="font-weight:600;white-space:nowrap"><?= htmlspecialchars($d['numero'] ?? '—') ?></td>
                <td><?= htmlspecialchars($d['produit'] ?? '—') ?></td>
                <td><span class="phase-tag"><?= htmlspecialchars($d['phase'] ?? '—') ?></span></td>
                <td class="<?= $etatCls ?>"><?= htmlspecialchars($d['etat'] ?? '—') ?></td>
                <td style="white-space:nowrap"><?= htmlspecialchars($d['date_demande'] ?? '—') ?></td>
                <td style="white-space:nowrap"><?= htmlspecialchars($d['date_remise'] ?? '—') ?></td>
                <td><?= htmlspecialchars($d['ville'] ?? '—') ?></td>
                <td style="text-align:right"><?= $tf ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
