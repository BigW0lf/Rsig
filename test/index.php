<?php
require 'flight/Flight.php';
require 'config.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '30');

function getDb(): ?PDO {
    try {
        return new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        return null;
    }
}

// ── Dynamics ───────────────────────────────────────────────
function getAccessToken(): string {
    $ch = curl_init("https://login.microsoftonline.com/" . DYN_TENANT_ID . "/oauth2/v2.0/token");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => DYN_CLIENT_ID,
            'client_secret' => DYN_CLIENT_SECRET,
            'scope'         => DYN_SCOPE,
        ]),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $json = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $json['access_token'] ?? '';
}

function callDynamics(string $value, string $field, string $table): array {
    $token = getAccessToken();
    $url   = "https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/$table"
           . '?$filter=' . urlencode("$field eq '" . addslashes($value) . "'")
           . '&$top=5';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Accept: application/json",
            "OData-Version: 4.0",
        ],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $result ?? [];
}

// ── Diagnostic BDD ────────────────────────────────────────
Flight::route('GET /api/db-check', function() {
    $info = [
        'pdo_drivers'    => PDO::getAvailableDrivers(),
        'pgsql_loaded'   => extension_loaded('pdo_pgsql'),
        'extension_dir'  => ini_get('extension_dir'),
        'php_version'    => PHP_VERSION,
    ];
    try {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
        $info['connection'] = 'OK';
        $info['pg_version'] = $pdo->query('SELECT version()')->fetchColumn();
    } catch (\Exception $e) {
        $info['connection'] = 'ERREUR: ' . $e->getMessage();
    }
    Flight::json($info);
});

// ── Pages ──────────────────────────────────────────────────
Flight::route('GET /', function() {
    Flight::render('accueil');
});

Flight::route('GET /crm', function() {
    Flight::render('crm');
});

Flight::route('GET /donnees', function() {
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

Flight::route('GET /maj-bdd', function() {
    Flight::render('maj_bdd', ['connected' => getDb() !== null]);
});

// ── API Géocodage ──────────────────────────────────────────
Flight::route('GET /search', function() {
    $search = trim($_GET['barre'] ?? '');

    if (empty($search)) {
        Flight::json(['error' => 'Paramètre manquant'], 400);
        return;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://data.geopf.fr/geocodage/completion/?text=" . urlencode($search) . "&maximumResponses=5",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($data['results'])) {
        Flight::json(['results' => []]);
        return;
    }

    Flight::json([
        'query'   => $search,
        'results' => array_map(fn($item) => [
            'label' => $item['fulltext']       ?? '',
            'city'  => $item['city']           ?? '',
            'lat'   => $item['y']              ?? null,
            'lon'   => $item['x']              ?? null,
            'class' => $item['classification'] ?? '',
        ], $data['results']),
    ]);
});

// ── API Dynamics ───────────────────────────────────────────
Flight::route('GET /api/commune/@code', function($code) {
    Flight::json(callDynamics($code, 'apo_codeinsee', 'apo_communes'));
});

Flight::route('POST /api/dynamics', function() {
    $body  = json_decode(file_get_contents('php://input'), true);
    $value = $body['value'] ?? null;
    $field = $body['field'] ?? null;
    $table = $body['table'] ?? null;

    if (!$value || !$field || !$table) {
        Flight::json(['error' => 'Paramètres manquants'], 400);
        return;
    }

    Flight::json(callDynamics($value, $field, $table));
});

// ── API SQL sécurisée ─────────────────────────────────────
Flight::route('POST /api/sql', function() {
    $body = json_decode(file_get_contents('php://input'), true);
    $sql  = trim($body['sql'] ?? '');

    if (empty($sql)) {
        Flight::json(['error' => 'Requête vide'], 400);
        return;
    }

    // Bloquer DROP et DELETE
    if (preg_match('/^\s*(DROP|DELETE)\b/i', $sql)) {
        Flight::json(['error' => 'DROP et DELETE ne sont pas autorisés depuis cette interface'], 403);
        return;
    }

    $db = getDb();
    if (!$db) {
        Flight::json(['error' => 'Base de données non disponible'], 503);
        return;
    }

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $count = $stmt->rowCount();
        Flight::json(['message' => "$count ligne(s) affectée(s)."]);
    } catch (PDOException $e) {
        Flight::json(['error' => $e->getMessage()], 500);
    }
});

// ── Upload CSV ─────────────────────────────────────────────
Flight::route('POST /api/upload-csv', function() {
    if (empty($_FILES['file'])) {
        Flight::json(['error' => 'Aucun fichier reçu'], 400);
        return;
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        Flight::json(['error' => 'Erreur upload'], 400);
        return;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        Flight::json(['error' => 'Seuls les fichiers CSV sont acceptés'], 400);
        return;
    }

    // Dossier de dépôt NiFi — adapte ce chemin si besoin
    $nifiDir = __DIR__ . '/nifi-drop/';
    if (!is_dir($nifiDir)) {
        mkdir($nifiDir, 0755, true);
    }

    $dest = $nifiDir . basename($file['name']);
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        Flight::json(['error' => 'Impossible de déplacer le fichier'], 500);
        return;
    }

    Flight::json(['message' => 'Fichier déposé dans le dossier NiFi : ' . basename($dest)]);
});

// ════════════════════════════════════════════════════════════
// API BDD — couches SIG
// ════════════════════════════════════════════════════════════

function parseBbox(): ?array {
    $b = Flight::request()->query['bbox'] ?? null;
    if (!$b) return null;
    $p = array_map('floatval', explode(',', $b));
    return count($p) === 4 ? $p : null;
}

function bboxWhere(array $b, string $geomCol = 'geom', string $srid = '2154'): string {
    return "ST_Intersects($geomCol, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),$srid))";
}

function bindBbox(PDOStatement $stmt, array $b): void {
    $stmt->bindValue(':x1', $b[0]); $stmt->bindValue(':y1', $b[1]);
    $stmt->bindValue(':x2', $b[2]); $stmt->bindValue(':y2', $b[3]);
}

function rowsToGeoJson(PDOStatement $stmt): array {
    $features = [];
    foreach ($stmt as $row) {
        $raw = $row['geojson'];
        if (is_resource($raw)) $raw = stream_get_contents($raw);
        $g = json_decode((string)$raw, true);
        unset($row['geojson']);
        $features[] = ['type' => 'Feature', 'geometry' => $g, 'properties' => $row];
    }
    return $features;
}

// ── taux_clean (communes) ──────────────────────────────────
Flight::route('GET /api/taux', function () {
    $b = parseBbox();
    $champ = Flight::request()->query['champ'] ?? 'taux_fb_commune_vote';
    $allowed = ['taux_fnb_commune','taux_fnb_syndicats_net','taux_fnb_gfp_vote',
                'taux_tafnb_commune_net','taux_tafnb_gfp_net','taux_tse_net',
                'taux_tse_gemapi_net','taux_fb_commune_vote','taux_fb_syndicats_net',
                'taux_fb_gfp_vote','taux_teom_plein'];
    if (!in_array($champ, $allowed, true)) $champ = 'taux_fb_commune_vote';

    // taux_clean : coordonnées WGS84 mais SRID déclaré 2154 → ST_SetSRID pour corriger à la volée
    $where = $b ? 'WHERE ST_Intersects(ST_SetSRID(geom,4326), ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326))' : '';
    $sql = "SELECT ogc_fid, dep, com, libcom, millesime,
                   taux_fnb_commune, taux_fb_commune_vote, taux_tse_net, taux_teom_plein,
                   $champ AS valeur_affichee,
                   ST_AsGeoJSON(ST_SetSRID(geom,4326),6)::text AS geojson
            FROM taux_clean $where LIMIT 5000";

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    if ($b) bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── Stats globales taux (quintiles sur tout le dataset) ────
Flight::route('GET /api/taux/stats', function () {
    $champ = Flight::request()->query['champ'] ?? 'taux_fb_commune_vote';
    $allowed = ['taux_fnb_commune','taux_fnb_syndicats_net','taux_fnb_gfp_vote',
                'taux_tafnb_commune_net','taux_tafnb_gfp_net','taux_tse_net',
                'taux_tse_gemapi_net','taux_fb_commune_vote','taux_fb_syndicats_net',
                'taux_fb_gfp_vote','taux_teom_plein'];
    if (!in_array($champ, $allowed, true)) $champ = 'taux_fb_commune_vote';

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT
        percentile_cont(0.00) WITHIN GROUP (ORDER BY $champ::numeric) AS p0,
        percentile_cont(0.20) WITHIN GROUP (ORDER BY $champ::numeric) AS p20,
        percentile_cont(0.40) WITHIN GROUP (ORDER BY $champ::numeric) AS p40,
        percentile_cont(0.60) WITHIN GROUP (ORDER BY $champ::numeric) AS p60,
        percentile_cont(0.80) WITHIN GROUP (ORDER BY $champ::numeric) AS p80
        FROM taux_clean WHERE $champ IS NOT NULL";
    $row = $db->query($sql)->fetch();
    ini_set('serialize_precision', 4);
    Flight::json(array_values(array_map(fn($v) => round((float)$v, 4), $row)));
});

// ── Taux départements (avg par dept, géom departements_geom) ──
Flight::route('GET /api/taux/departements', function () {
    $champ = Flight::request()->query['champ'] ?? 'taux_fb_commune_vote';
    $allowed = ['taux_fnb_commune','taux_fnb_syndicats_net','taux_fnb_gfp_vote',
                'taux_tafnb_commune_net','taux_tafnb_gfp_net','taux_tse_net',
                'taux_tse_gemapi_net','taux_fb_commune_vote','taux_fb_syndicats_net',
                'taux_fb_gfp_vote','taux_teom_plein'];
    if (!in_array($champ, $allowed, true)) $champ = 'taux_fb_commune_vote';

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    // Vue matérialisée pré-simplifiée en WGS84 pour éviter ST_Transform à la volée
    $sql = "SELECT d.code_insee AS code_dep,
                   d.nom_officiel AS nom_dep,
                   ROUND(AVG(tc.$champ::numeric)::numeric, 4) AS valeur_affichee,
                   ST_AsGeoJSON(d.geom,4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN taux_clean tc ON lpad(tc.dep,2,'0') = d.code_insee
            WHERE tc.$champ IS NOT NULL
            GROUP BY d.code_insee, d.nom_officiel, d.geom";
    $stmt = $db->query($sql);
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── coeff_loc_final (parcelles, bbox obligatoire) ──────────
Flight::route('GET /api/coeff', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }

    $sql = "SELECT ogc_fid, idu, codecommune, section, parcelle,
                   coeff_2017, coeff_2018, coeff_2019, coeff_2020,
                   coeff_2024, coeff_2026, evolution,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_Transform(geom,4326),0.00001),5) AS geojson
            FROM coeff_loc_final
            WHERE " . bboxWhere($b) . " LIMIT 4000";

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── dossier_acc_geo (points) ───────────────────────────────
Flight::route('GET /api/dossiers', function () {
    $b = parseBbox();
    $where = $b ? 'WHERE geom IS NOT NULL AND ' . bboxWhere($b, 'geom::geometry') : 'WHERE geom IS NOT NULL';

    $sql = "SELECT ogc_fid, rtx_code, name, apo_montanttaxefonciere,
                   adresse_complete, dossier, lot, prefix, section, insee,
                   ST_AsGeoJSON(ST_Transform(geom::geometry,4326),6) AS geojson
            FROM dossier_acc_geo $where";

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    if ($b) bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── sections_tarifs (sections, bbox + categorie) ───────────
Flight::route('GET /api/tarifs', function () {
    $b   = parseBbox();
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$b || !$cat) { Flight::json(['error' => 'bbox et categorie requis'], 400); return; }
    if (!preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie invalide'], 400); return; }

    $annee   = Flight::request()->query['annee'] ?? '2025';
    $annees  = ['2017','2019','2020','2021','2022','2023','2024','2025','2026'];
    if (!in_array($annee, $annees, true)) $annee = '2025';
    $col = "val_$annee";

    $sql = "SELECT s.ogc_fid, s.code_dep, s.code_insee, s.nom_com, s.section, s.secteur,
                   t.categorie, t.$col AS valeur,
                   t.val_2017,t.val_2019,t.val_2020,t.val_2021,
                   t.val_2022,t.val_2023,t.val_2024,t.val_2025,t.val_2026,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_Transform(s.geom,4326),0.0001),4) AS geojson
            FROM sections_2025 s
            JOIN tarifs_pivot t
                ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
               AND t.num_secteur = s.secteur AND t.categorie = :cat
            WHERE " . bboxWhere($b, 's.geom') . " LIMIT 5000";

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── Liste catégories tarifs ────────────────────────────────
Flight::route('GET /api/tarifs/categories', function () {
    $db = getDb(); if (!$db) { Flight::json([], 503); return; }
    $stmt = $db->query("SELECT DISTINCT categorie FROM tarifs_pivot ORDER BY categorie");
    Flight::json($stmt->fetchAll(PDO::FETCH_COLUMN));
});

// ── Coeff clusters (un point par commune, France entière) ──
Flight::route('GET /api/coeff/clusters', function () {
    $champ = Flight::request()->query['champ'] ?? 'coeff_2026';
    $allowed = ['coeff_2017','coeff_2018','coeff_2019','coeff_2020','coeff_2024','coeff_2026'];
    if (!in_array($champ, $allowed, true)) $champ = 'coeff_2026';

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT codecommune, $champ AS valeur, nb_parcelles,
                   ST_AsGeoJSON(geom::geometry, 5)::text AS geojson
            FROM coeff_clusters
            WHERE $champ IS NOT NULL
            ORDER BY codecommune";
    $stmt = $db->query($sql);
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── Stats globales coeff (quintiles sur tout le dataset) ───
Flight::route('GET /api/coeff/stats', function () {
    $champ = Flight::request()->query['champ'] ?? 'coeff_2026';
    $allowed = ['coeff_2017','coeff_2018','coeff_2019','coeff_2020','coeff_2024','coeff_2026'];
    if (!in_array($champ, $allowed, true)) $champ = 'coeff_2026';

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    // Les coefficients ont des valeurs discrètes (0.7, 0.85, 0.9, 1.0, 1.1 …)
    // On retourne les valeurs distinctes triées comme breaks naturels
    $sql = "SELECT DISTINCT $champ::numeric AS val
            FROM coeff_loc_final WHERE $champ IS NOT NULL
            ORDER BY val";
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    // Forcer 4 décimales max pour éviter la précision IEEE 754 dans JSON
    ini_set('serialize_precision', 4);
    Flight::json(array_values(array_map(fn($v) => round((float)$v, 4), $rows)));
});

// ── Stats globales tarifs (quintiles sur tout le dataset) ──
Flight::route('GET /api/tarifs/stats', function () {
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie invalide'], 400); return; }
    $annee  = Flight::request()->query['annee'] ?? '2025';
    $annees = ['2017','2019','2020','2021','2022','2023','2024','2025','2026'];
    if (!in_array($annee, $annees, true)) $annee = '2025';
    $col = "val_$annee";

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT
        percentile_cont(0.00) WITHIN GROUP (ORDER BY $col) AS p0,
        percentile_cont(0.143) WITHIN GROUP (ORDER BY $col) AS p1,
        percentile_cont(0.286) WITHIN GROUP (ORDER BY $col) AS p2,
        percentile_cont(0.429) WITHIN GROUP (ORDER BY $col) AS p3,
        percentile_cont(0.571) WITHIN GROUP (ORDER BY $col) AS p4,
        percentile_cont(0.714) WITHIN GROUP (ORDER BY $col) AS p5,
        percentile_cont(0.857) WITHIN GROUP (ORDER BY $col) AS p6
        FROM tarifs_pivot WHERE categorie = :cat AND $col IS NOT NULL";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->execute();
    $row = $stmt->fetch();
    Flight::json(array_values(array_map('floatval', $row)));
});

// ── Tarifs communes (géométrie réelle depuis taux_clean) ───
Flight::route('GET /api/tarifs/communes', function () {
    $b   = parseBbox();
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$b || !$cat) { Flight::json(['error' => 'bbox et categorie requis'], 400); return; }
    if (!preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie invalide'], 400); return; }

    $annee  = Flight::request()->query['annee'] ?? '2025';
    $annees = ['2017','2019','2020','2021','2022','2023','2024','2025','2026'];
    if (!in_array($annee, $annees, true)) $annee = '2025';
    $col = "val_$annee";

    // communes_geom : géométrie OSM WGS84, champ insee (5 car)
    $sql = "SELECT c.insee AS code_insee,
                   left(c.insee,2) AS code_dep,
                   c.nom AS nom_com,
                   t_avg.valeur,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(c.geom,0.0002),4) AS geojson
            FROM communes_geom c
            JOIN (
                SELECT left(s.code_insee,5) AS code_insee,
                       ROUND(AVG(t.$col::numeric),2) AS valeur
                FROM sections_2025 s
                JOIN tarifs_pivot t
                  ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
                 AND t.num_secteur = s.secteur AND t.categorie = :cat
                WHERE t.$col IS NOT NULL
                  AND ST_Intersects(s.geom, ST_Transform(ST_MakeEnvelope(:ix1,:iy1,:ix2,:iy2,4326),2154))
                GROUP BY left(s.code_insee,5)
            ) t_avg ON c.insee = t_avg.code_insee
            WHERE ST_Intersects(c.geom, ST_MakeEnvelope(:ox1,:oy1,:ox2,:oy2,4326))
            LIMIT 1500";

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    // inner CTE (sections bbox, SRID 2154)
    $stmt->bindValue(':ix1', $b[0]); $stmt->bindValue(':iy1', $b[1]);
    $stmt->bindValue(':ix2', $b[2]); $stmt->bindValue(':iy2', $b[3]);
    // outer communes_geom bbox (WGS84)
    $stmt->bindValue(':ox1', $b[0]); $stmt->bindValue(':oy1', $b[1]);
    $stmt->bindValue(':ox2', $b[2]); $stmt->bindValue(':oy2', $b[3]);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── Tarifs départements (zoom très bas — avg par dept, données seules sans géom) ──
Flight::route('GET /api/tarifs/departements', function () {
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat) { Flight::json(['error' => 'categorie requis'], 400); return; }
    if (!preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie invalide'], 400); return; }

    $annee  = Flight::request()->query['annee'] ?? '2025';
    $annees = ['2017','2019','2020','2021','2022','2023','2024','2025','2026'];
    if (!in_array($annee, $annees, true)) $annee = '2025';
    $col = "val_$annee";

    $sql = "SELECT d.code_insee AS code_dep,
                   d.nom_officiel AS nom_dep,
                   t_avg.valeur,
                   ST_AsGeoJSON(d.geom,4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN (
                SELECT dep AS code_dep, ROUND(AVG($col::numeric),2) AS valeur
                FROM tarifs_pivot
                WHERE categorie = :cat AND $col IS NOT NULL
                GROUP BY dep
            ) t_avg ON d.code_insee = t_avg.code_dep";

    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── API NiFi status ────────────────────────────────────────
Flight::route('GET /api/nifi/status', function() {
    $ch = curl_init(NIFI_BASE . '/nifi-api/system-diagnostics');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // 401 = NiFi répond mais demande auth = il tourne
    $up = ($code >= 200 && $code < 500 && empty($err));
    Flight::json(['status' => $up ? 'up' : 'down', 'code' => $code]);
});

Flight::start();
