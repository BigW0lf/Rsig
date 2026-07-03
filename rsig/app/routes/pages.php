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

// ── Tracking couches actives (appelé depuis le JS map) ────
Flight::route('POST /api/track/layers', function () {
    requireAuth();
    $body   = Flight::request()->data;
    $layers = $body['layers'] ?? null;
    if (!is_array($layers)) { Flight::json(['ok' => false]); return; }
    // Sanitize : uniquement des strings courtes
    $layers = array_values(array_filter(array_map(fn($l) => is_string($l) ? substr(strip_tags($l), 0, 64) : null, $layers)));
    $db = getDb();
    if (!$db) { Flight::json(['ok' => false]); return; }
    try {
        // Mettre à jour la dernière visite carte de cet utilisateur (dans les 2h)
        $email = $_SESSION['user_email'] ?? null;
        $stmt  = $db->prepare(
            "UPDATE site_visits SET layers_used = :layers
             WHERE id = (
                 SELECT id FROM site_visits
                 WHERE page = 'carte' AND user_email = :email
                   AND visited_at > now() - interval '2 hours'
                 ORDER BY visited_at DESC LIMIT 1
             )"
        );
        $stmt->execute([':layers' => json_encode($layers), ':email' => $email]);
        Flight::json(['ok' => true]);
    } catch (\Exception $e) {
        Flight::json(['ok' => false, 'error' => $e->getMessage()]);
    }
});

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

    Flight::render('donnees', compact('db', 'tables', 'table', 'cols', 'rows') + ['connected' => $db !== null, 'isAdmin' => isAdmin()]);
});

Flight::route('GET /maj-bdd',  function () { requireAuth(); Flight::render('maj_bdd_archive', ['connected' => getDb() !== null]); });
Flight::route('GET /requetes', function () { requireAuth(); trackVisit('requetes'); Flight::render('requetes', ['connected' => getDb() !== null]); });
Flight::route('GET /bofip',    function () { requireAuth(); trackVisit('bofip'); Flight::render('bofip'); });
// ── Mise à jour — admin uniquement ───────────────────────
Flight::route('GET /maj',      function () { requireAdmin(); trackVisit('maj'); Flight::render('maj'); });
// ── Fiche client CRM ─────────────────────────────────────
Flight::route('GET /client/@account_id', function ($account_id) {
    requireAuth();
    $db = getDb();
    if (!$db) { http_response_code(503); echo 'Base non disponible'; return; }
    $stmt = $db->prepare("SELECT * FROM crm_accounts WHERE account_id = :id");
    $stmt->execute([':id' => $account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$account) { http_response_code(404); echo 'Client introuvable'; return; }
    $stmt2 = $db->prepare("
        SELECT numero, produit, phase, etat, date_demande, date_remise,
               ville, code_postal, code_insee, montant_tf, adresse, auditeur
        FROM crm_dossiers
        WHERE account_id = :id
        ORDER BY date_demande DESC NULLS LAST
    ");
    $stmt2->execute([':id' => $account_id]);
    $dossiers = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    Flight::render('client_crm', ['account' => $account, 'dossiers' => $dossiers]);
});

// ── Stats admin ───────────────────────────────────────────
Flight::route('GET /admin/stats', function () {
    requireAdmin();
    // Rendre la vue standalone (pas de render Flight — PHP pur avec require)
    require __DIR__ . '/../../views/admin_stats.php';
    exit;
});
