<?php

// ── Auth ──────────────────────────────────────────────────
Flight::route('GET /auth/callback', function () { handleAuthCallback(); });
Flight::route('GET /auth/logout',   function () { handleLogout(); });

// ── Pages protégées ───────────────────────────────────────
Flight::route('GET /', function () { requireAuth(); Flight::render('accueil'); });
Flight::route('GET /crm', function () { requireAuth(); Flight::render('crm'); });

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

Flight::route('GET /maj-bdd', function () { requireAuth(); Flight::render('maj_bdd_archive', ['connected' => getDb() !== null]); });
Flight::route('GET /requetes', function () { requireAuth(); Flight::render('requetes', ['connected' => getDb() !== null]); });
