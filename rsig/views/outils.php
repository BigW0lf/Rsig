<?php
requireAuth();

$downloadsDir = __DIR__ . '/../downloads/';
$files = [];
if (is_dir($downloadsDir)) {
    foreach (scandir($downloadsDir) as $f) {
        if ($f[0] === '.') continue;
        $path = $downloadsDir . $f;
        if (!is_file($path)) continue;
        $files[] = [
            'name' => $f,
            'size' => filesize($path),
            'mtime' => filemtime($path),
        ];
    }
    usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
}

function fmtSize(int $bytes): string {
    if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' Mo';
    if ($bytes >= 1024)        return round($bytes / 1024, 0) . ' Ko';
    return $bytes . ' o';
}

function fileIcon(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match($ext) {
        'exe', 'msi' => '&#x1F4E6;',
        'zip', 'rar', '7z' => '&#x1F4C2;',
        'pdf' => '&#x1F4C4;',
        default => '&#x1F4BE;',
    };
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Autres outils — RSig</title>
    <link rel="stylesheet" href="/assets/style.css?v=<?= ASSET_VER ?>">
    <style>
        body { background: var(--surface2); min-height: 100vh; }
        .page-header {
            background: var(--blue);
            color: #fff;
            padding: 0 28px;
            height: 48px;
            display: flex;
            align-items: center;
            gap: 16px;
            font-weight: 700;
            font-size: 1rem;
        }
        .page-header a { color: rgba(255,255,255,.7); text-decoration: none; font-size: .85rem; font-weight: 400; }
        .page-header a:hover { color: #fff; }
        .page-body { max-width: 860px; margin: 36px auto; padding: 0 20px; }
        .section-title {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--text3);
            margin-bottom: 14px;
        }
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 14px;
        }
        .tool-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            transition: box-shadow .15s, border-color .15s;
        }
        .tool-card:hover { box-shadow: 0 2px 12px rgba(0,49,137,.08); border-color: var(--blue-light); }
        .tool-card-icon { font-size: 1.6rem; line-height: 1; }
        .tool-card-name { font-weight: 700; font-size: .95rem; color: var(--text); }
        .tool-card-meta { font-size: .75rem; color: var(--text3); }
        .tool-card-date { font-size: .72rem; color: var(--text3); margin-top: 2px; }
        .tool-card-date strong { color: var(--text2); font-weight: 600; }
        .tool-card-dl {
            margin-top: auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: var(--blue);
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            font-size: .8rem;
            font-weight: 600;
            transition: background .15s;
            align-self: flex-start;
        }
        .tool-card-dl:hover { background: var(--blue-light); }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text3);
            font-size: .9rem;
        }
        .empty-state p { margin: 8px 0 0; font-size: .8rem; }
    </style>
</head>
<body>
<div class="page-header">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/>
        <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/>
    </svg>
    RSig &mdash; Autres outils
    <a href="/" style="margin-left:auto">&#x2190; Retour à la carte</a>
</div>

<div class="page-body">
    <div class="section-title">Installeurs disponibles</div>

    <?php if (empty($files)): ?>
    <div class="empty-state">
        Aucun installeur disponible pour le moment.
        <p>Les fichiers déposés dans le dossier <code>downloads/</code> apparaîtront ici.</p>
    </div>
    <?php else: ?>
    <div class="tools-grid">
        <?php foreach ($files as $file): ?>
        <div class="tool-card">
            <div class="tool-card-icon"><?= fileIcon($file['name']) ?></div>
            <div class="tool-card-name"><?= htmlspecialchars(pathinfo($file['name'], PATHINFO_FILENAME)) ?></div>
            <div class="tool-card-meta">
                <?= htmlspecialchars(strtoupper(pathinfo($file['name'], PATHINFO_EXTENSION))) ?>
                &bull; <?= fmtSize($file['size']) ?>
            </div>
            <div class="tool-card-date">
                Publié le <strong><?= date('d/m/Y', $file['mtime']) ?></strong>
            </div>
            <a class="tool-card-dl" href="/dl/<?= rawurlencode($file['name']) ?>" download>
                &#x2193; Télécharger
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
