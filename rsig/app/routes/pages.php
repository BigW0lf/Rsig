<?php

// ── Auth ──────────────────────────────────────────────────
Flight::route('GET /auth/callback', function () { handleAuthCallback(); });
Flight::route('GET /auth/logout',   function () { handleLogout(); });

// ── Tracking visites ──────────────────────────────────────
function trackVisit(string $page, ?string $layers = null): void {
    $db = getDb();
    if (!$db) return;
    try {
        // Créer la table si elle n'existe pas (first-run)
        $db->exec("CREATE TABLE IF NOT EXISTS site_visits (
            id           SERIAL PRIMARY KEY,
            visited_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
            page         TEXT NOT NULL,
            user_name    TEXT,
            user_email   TEXT,
            ip           TEXT,
            layers_used  TEXT
        )");
        $stmt = $db->prepare(
            "INSERT INTO site_visits (page, user_name, user_email, ip, layers_used)
             VALUES (:page, :name, :email, :ip, :layers)"
        );
        $stmt->execute([
            ':page'   => $page,
            ':name'   => $_SESSION['user_name']  ?? null,
            ':email'  => $_SESSION['user_email'] ?? null,
            ':ip'     => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
            ':layers' => $layers,
        ]);
    } catch (\Exception) {}
}

// ── Pages protégées ───────────────────────────────────────
Flight::route('GET /', function () { requireAuth(); trackVisit('carte'); Flight::render('accueil'); });
Flight::route('GET /crm', function () { requireAuth(); trackVisit('crm'); Flight::render('crm'); });

Flight::route('GET /donnees', function () { requireAuth();
    $db     = getDb();
    $tables = [];
    $cols   = [];
    $rows   = [];
    $table  = Flight::request()->query['table'] ?? null;

    if ($db) {
        $stmt   = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public' ORDER BY table_name");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$table && $tables) $table = $tables[0];
        if ($table && in_array($table, $tables, true)) {
            $stmt = $db->query('SELECT * FROM "' . $table . '" LIMIT 100');
            $rows = $stmt->fetchAll();
            $cols = $rows ? array_keys($rows[0]) : [];
        }
    }

    Flight::render('donnees', compact('db', 'tables', 'table', 'cols', 'rows') + ['connected' => $db !== null]);
});

Flight::route('GET /maj-bdd',  function () { requireAuth(); Flight::render('maj_bdd_archive', ['connected' => getDb() !== null]); });
Flight::route('GET /requetes', function () { requireAuth(); trackVisit('requetes'); Flight::render('requetes', ['connected' => getDb() !== null]); });
Flight::route('GET /bofip',    function () { requireAuth(); trackVisit('bofip'); Flight::render('bofip'); });
// ── Mise à jour — admin uniquement ───────────────────────
Flight::route('GET /maj',      function () { requireAdmin(); trackVisit('maj'); Flight::render('maj'); });
// ── Stats admin ───────────────────────────────────────────
Flight::route('GET /admin/stats', function () {
    requireAdmin();
    // Rendre la vue standalone (pas de render Flight — PHP pur avec require)
    require __DIR__ . '/../../views/admin_stats.php';
    exit;
});
