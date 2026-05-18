<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mini SIG — Données</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<nav>
    <span class="nav-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/></svg>
        Mini SIG
    </span>
    <a href="/">Carte</a>
    <a href="/donnees" class="active">Données</a>
    <a href="/maj-bdd">Mise à jour BDD</a>
    <a href="/crm">CRM</a>
    <span class="nav-spacer"></span>
    <a href="https://localhost:8443/nifi" target="_blank" class="nifi-link">NiFi</a>
</nav>

<div class="page-content">
    <h1>Données</h1>

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
</div>

</body>
</html>
