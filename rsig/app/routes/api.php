<?php
require_once __DIR__ . '/../crm_sync.php';

// ── Sync CRM → miroir PostgreSQL (lance en arrière-plan) ──
Flight::route('POST /api/crm/sync', function () {
    $db = getDb();
    if (!$db) { Flight::json(['error' => 'Base non disponible'], 503); return; }

    // Vérifie qu'une sync n'est pas déjà en cours
    $running = $db->query("SELECT id FROM crm_sync_log WHERE status='running' AND started_at > now() - interval '10 minutes'")->fetchColumn();
    if ($running) { Flight::json(['status' => 'already_running', 'log_id' => $running]); return; }

    // Crée l'entrée de log
    $logStmt = $db->prepare("INSERT INTO crm_sync_log (started_at, status, message) VALUES (now(), 'running', '') RETURNING id");
    $logStmt->execute();
    $logId = (int)$logStmt->fetchColumn();

    // Lance le worker en arrière-plan
    $php    = PHP_CLI_PATH;
    $worker = realpath(__DIR__ . '/../sync_worker.php');
    $ini    = PHP_INI_PATH;
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = '"' . $php . '"' . ($ini ? ' -c "' . $ini . '"' : '') . ' "' . $worker . '" ' . $logId . ' >NUL 2>&1';
        pclose(popen('start "" /B ' . $cmd, 'r'));
    } else {
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . $logId . ' >/dev/null 2>&1 &';
        shell_exec($cmd);
    }

    Flight::json(['status' => 'started', 'log_id' => $logId]);
});

// ── Statut dernière sync ──────────────────────────────────
Flight::route('GET /api/crm/sync/status', function () {
    $db = getDb();
    if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $row = $db->query("SELECT * FROM crm_sync_log ORDER BY id DESC LIMIT 1")->fetch();
    $counts = $db->query("SELECT COUNT(*) AS s FROM crm_sites_mirror")->fetchColumn();
    $countd = $db->query("SELECT COUNT(*) AS d FROM crm_dossiers_mirror")->fetchColumn();
    Flight::json([
        'last_sync' => $row ?: null,
        'sites_in_db'    => (int)$counts,
        'dossiers_in_db' => (int)$countd,
    ]);
});

// ── GeoJSON dossiers — miroir CRM si peuplé, sinon dossier_acc_geo ─
Flight::route('GET /api/crm/geojson', function () {
    $db = getDb();
    if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }

    $mirrorCount = (int)$db->query("SELECT COUNT(*) FROM crm_dossiers_mirror")->fetchColumn();

    if ($mirrorCount > 0) {
        // Données fraîches depuis le miroir CRM
        // montant_tf : depuis le site miroir, sinon fallback dossier_acc_geo
        $sql = "SELECT
                    d.dossierid                        AS ogc_fid,
                    d.numero                           AS dossier,
                    d.client_name                      AS name,
                    d.reference_client                 AS rtx_code,
                    s.adresse                          AS adresse_complete,
                    s.ville,
                    s.code_postal,
                    s.code_insee                       AS insee,
                    s.section,
                    s.parcelle,
                    s.lot,
                    COALESCE(s.montant_tf, old.apo_montanttaxefonciere) AS apo_montanttaxefonciere,
                    d.date_demande,
                    d.date_remise,
                    ST_AsGeoJSON(s.geom, 6)::text      AS geojson
                FROM crm_dossiers_mirror d
                JOIN crm_sites_mirror s ON s.siteid = d.site_id
                LEFT JOIN dossier_acc_geo old ON old.dossier = d.numero
                WHERE s.geom IS NOT NULL";
    } else {
        // Fallback : table dossier_acc_geo existante (x/y Lambert 93)
        $sql = "SELECT
                    ogc_fid::text                      AS ogc_fid,
                    dossier,
                    name,
                    rtx_code,
                    adresse_complete,
                    NULL::text                         AS ville,
                    cp                                 AS code_postal,
                    insee,
                    section,
                    NULL::text                         AS parcelle,
                    lot,
                    apo_montanttaxefonciere,
                    NULL::date                         AS date_demande,
                    NULL::date                         AS date_remise,
                    ST_AsGeoJSON(ST_Transform(geom::geometry, 4326), 6)::text AS geojson
                FROM dossier_acc_geo
                WHERE geom IS NOT NULL";
    }

    $stmt = $db->query($sql);
    $features = [];
    foreach ($stmt as $row) {
        $g = json_decode($row['geojson'], true);
        unset($row['geojson']);
        $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>$row];
    }
    Flight::json(['type'=>'FeatureCollection','features'=>$features]);
});

// ── Requêtes SELECT (lecture seule) ───────────────────────
Flight::route('POST /api/query', function () {
    $body = json_decode(file_get_contents('php://input'), true);
    $sql  = trim($body['sql'] ?? '');

    if (empty($sql)) { Flight::json(['error' => 'Requête vide'], 400); return; }

    // Autoriser uniquement SELECT (et WITH … SELECT pour les CTE)
    // Normaliser les espaces/retours pour éviter les bypass type "\nSELECT"
    $sqlNorm = preg_replace('/\s+/', ' ', $sql);
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sqlNorm)) {
        Flight::json(['error' => 'Seules les requêtes SELECT sont autorisées'], 403);
        return;
    }
    // Bloquer les mots-clés dangereux même dans un SELECT imbriqué
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE|ALTER|CREATE|GRANT|REVOKE|EXECUTE|COPY)\b/i', $sqlNorm)) {
        Flight::json(['error' => 'Requête non autorisée'], 403);
        return;
    }

    $db = getDb();
    if (!$db) { Flight::json(['error' => 'Base non disponible'], 503); return; }

    try {
        // N'ajoute LIMIT que si la requête n'en a pas déjà un
        $withLimit = preg_match('/\bLIMIT\s+\d+/i', $sql) ? $sql : $sql . ' LIMIT 500';
        $stmt = $db->query($withLimit);
        $rows = $stmt->fetchAll();
        $cols = $rows ? array_keys($rows[0]) : [];
        Flight::json(['cols' => $cols, 'rows' => $rows, 'count' => count($rows)]);
    } catch (PDOException $e) {
        Flight::json(['error' => $e->getMessage()], 500);
    }
});

// ── Autocomplete communes (nom → code_insee) ──────────────
Flight::route('GET /api/communes/search', function () {
    $q  = trim(Flight::request()->query['q'] ?? '');
    if (strlen($q) < 2) { Flight::json([]); return; }

    $db = getDb();
    if (!$db) { Flight::json([]); return; }

    $stmt = $db->prepare(
        "SELECT DISTINCT nom_com AS label, left(code_insee,5) AS code_insee
         FROM sections_2025
         WHERE nom_com ILIKE :q
         ORDER BY nom_com LIMIT 10"
    );
    $stmt->execute([':q' => '%' . $q . '%']);
    Flight::json($stmt->fetchAll());
});

// ── Diagnostic ────────────────────────────────────────────
Flight::route('GET /api/db-check', function () {
    $info = [
        'pdo_drivers'   => PDO::getAvailableDrivers(),
        'pgsql_loaded'  => extension_loaded('pdo_pgsql'),
        'extension_dir' => ini_get('extension_dir'),
        'php_version'   => PHP_VERSION,
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

// ── Géocodage ─────────────────────────────────────────────
Flight::route('GET /search', function () {
    $search = trim($_GET['barre'] ?? '');
    if (empty($search)) { Flight::json(['error' => 'Paramètre manquant'], 400); return; }

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
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $curlErr || $httpCode !== 200) {
        Flight::json(['results' => []]);
        return;
    }
    $data = json_decode($raw, true);
    if (empty($data['results'])) { Flight::json(['results' => []]); return; }

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

// ── Dynamics ──────────────────────────────────────────────
Flight::route('GET /api/commune/@code', fn($code) => Flight::json(callDynamics($code, 'apo_codeinsee', 'apo_communes')));

// ── Proxy OData générique (lecture, entités autorisées) ───
Flight::route('GET /api/commune/odata', function () {
    $allowed = ['apo_dossiers', 'apo_sites', 'apo_communes'];
    $entity  = Flight::request()->query['entity'] ?? '';
    if (!in_array($entity, $allowed, true)) {
        Flight::json(['error' => 'Entité non autorisée'], 403); return;
    }
    $top    = min((int)(Flight::request()->query['top']    ?? 10), 100);
    $filter = Flight::request()->query['filter'] ?? '';
    $select = Flight::request()->query['select'] ?? '';

    $params = '$top=' . $top;
    if ($filter) $params .= '&$filter=' . urlencode($filter);
    if ($select) $params .= '&$select=' . urlencode($select);

    $token = getAccessToken();
    $url   = "https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/{$entity}?{$params}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token","Accept: application/json","OData-Version: 4.0"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $httpCode !== 200) {
        Flight::json(['error' => 'Erreur Dynamics ' . $httpCode], 502); return;
    }
    Flight::json(json_decode($body, true) ?? ['error' => 'Réponse invalide']);
});

Flight::route('POST /api/dynamics', function () {
    $body  = json_decode(file_get_contents('php://input'), true);
    $value = $body['value'] ?? null;
    $field = $body['field'] ?? null;
    $table = $body['table'] ?? null;
    if (!$value || !$field || !$table) { Flight::json(['error' => 'Paramètres manquants'], 400); return; }
    Flight::json(callDynamics($value, $field, $table));
});

// ── SQL sécurisé ──────────────────────────────────────────
Flight::route('POST /api/sql', function () {
    $body = json_decode(file_get_contents('php://input'), true);
    $sql  = trim($body['sql'] ?? '');
    if (empty($sql)) { Flight::json(['error' => 'Requête vide'], 400); return; }
    $sqlNorm2 = preg_replace('/\s+/', ' ', $sql);
    if (preg_match('/\b(DROP|DELETE|TRUNCATE|ALTER|GRANT|REVOKE|COPY)\b/i', $sqlNorm2)) {
        Flight::json(['error' => 'Opération non autorisée depuis cette interface'], 403);
        return;
    }
    $db = getDb();
    if (!$db) { Flight::json(['error' => 'Base de données non disponible'], 503); return; }
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute();
        Flight::json(['message' => $stmt->rowCount() . ' ligne(s) affectée(s).']);
    } catch (PDOException $e) {
        Flight::json(['error' => $e->getMessage()], 500);
    }
});

// ── Upload CSV → NiFi drop ────────────────────────────────
Flight::route('POST /api/upload-csv', function () {
    if (empty($_FILES['file'])) { Flight::json(['error' => 'Aucun fichier reçu'], 400); return; }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { Flight::json(['error' => 'Erreur upload'], 400); return; }
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        Flight::json(['error' => 'Seuls les fichiers CSV sont acceptés'], 400); return;
    }
    $nifiDir = __DIR__ . '/../../nifi-drop/';
    if (!is_dir($nifiDir)) mkdir($nifiDir, 0755, true);
    $dest = $nifiDir . basename($file['name']);
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        Flight::json(['error' => 'Impossible de déplacer le fichier'], 500); return;
    }
    Flight::json(['message' => 'Fichier déposé : ' . basename($dest)]);
});

// ── NiFi status ───────────────────────────────────────────
Flight::route('GET /api/nifi/status', function () {
    $ch = curl_init(NIFI_BASE . '/nifi-api/system-diagnostics');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
                            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $up = ($code >= 200 && $code < 500 && empty($err));
    Flight::json(['status' => $up ? 'up' : 'down', 'code' => $code]);
});

// ── Taux fiscaux ──────────────────────────────────────────

Flight::route('GET /api/taux/millesimes', function () {
    $db = getDb(); if (!$db) { Flight::json([], 503); return; }
    $rows = $db->query("SELECT DISTINCT millesime FROM taux_clean WHERE millesime IS NOT NULL ORDER BY millesime DESC")->fetchAll(PDO::FETCH_COLUMN);
    Flight::json($rows);
});

Flight::route('GET /api/taux', function () {
    $b         = parseBbox();
    $champ     = validateChamp(Flight::request()->query['champ'] ?? '', TAUX_CHAMPS, 'taux_fb_commune_vote');
    $millesime = validateMillesime(Flight::request()->query['millesime'] ?? '2025');
    $conds     = ["millesime = :millesime"];
    if ($b) $conds[] = "ST_Intersects(ST_SetSRID(geom,4326), ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326))";
    $where = 'WHERE ' . implode(' AND ', $conds);
    $sql   = "SELECT ogc_fid, dep, com, libcom, millesime,
                     taux_fb_commune_vote, taux_fb_syndicats_net, taux_fb_gfp_vote,
                     taux_tse_net, taux_tafnb_commune_net, taux_teom_plein, taux_tse_gemapi_net,
                     taux_fnb_commune, taux_fnb_syndicats_net, taux_fnb_gfp_vote, taux_tafnb_gfp_net,
                     $champ AS valeur_affichee,
                     ST_AsGeoJSON(ST_SetSRID(geom,4326),6)::text AS geojson
              FROM taux_clean $where LIMIT 5000";
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':millesime', $millesime);
    if ($b) bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

Flight::route('GET /api/taux/stats', function () {
    $champ     = validateChamp(Flight::request()->query['champ'] ?? '', TAUX_CHAMPS, 'taux_fb_commune_vote');
    $millesime = validateMillesime(Flight::request()->query['millesime'] ?? '2025');
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT
        percentile_cont(0.00) WITHIN GROUP (ORDER BY $champ::numeric) AS p0,
        percentile_cont(0.20) WITHIN GROUP (ORDER BY $champ::numeric) AS p20,
        percentile_cont(0.40) WITHIN GROUP (ORDER BY $champ::numeric) AS p40,
        percentile_cont(0.60) WITHIN GROUP (ORDER BY $champ::numeric) AS p60,
        percentile_cont(0.80) WITHIN GROUP (ORDER BY $champ::numeric) AS p80
        FROM taux_clean WHERE $champ IS NOT NULL AND millesime = :millesime";
    $stmt = $db->prepare($sql);
    $stmt->execute([':millesime' => $millesime]);
    $row = $stmt->fetch();
    ini_set('serialize_precision', 4);
    Flight::json(array_values(array_map(fn($v) => round((float)$v, 4), $row)));
});

Flight::route('GET /api/taux/departements', function () {
    $champ     = validateChamp(Flight::request()->query['champ'] ?? '', TAUX_CHAMPS, 'taux_fb_commune_vote');
    $millesime = validateMillesime(Flight::request()->query['millesime'] ?? '2025');
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                   ROUND(AVG(tc.$champ::numeric)::numeric, 4) AS valeur_affichee,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom, 0.01),4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN taux_clean tc ON lpad(tc.dep,2,'0') = d.code_insee
            WHERE tc.$champ IS NOT NULL AND tc.millesime = :millesime
            GROUP BY d.code_insee, d.nom_officiel, d.geom";
    $stmt = $db->prepare($sql);
    $stmt->execute([':millesime' => $millesime]);
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── CFE ──────────────────────────────────────────────────
// Toutes les routes CFE requièrent categorie + annee (indicateur × tarif TF)

Flight::route('GET /api/cfe/stats', function () {
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat || !preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie requise'], 400); return; }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $sql = "SELECT MIN(v.indicateur_cfe_m2 * t_avg.tarif) AS vmin,
                   MAX(v.indicateur_cfe_m2 * t_avg.tarif) AS vmax
            FROM cfe_calcul v
            JOIN (
                SELECT left(s.code_insee,5) AS code_insee, AVG(t.$col::numeric) AS tarif
                FROM sections_2025 s
                JOIN tarifs_pivot t
                  ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
                 AND t.num_secteur = s.secteur AND t.categorie = :cat
                WHERE t.$col IS NOT NULL
                GROUP BY left(s.code_insee,5)
            ) t_avg ON v.code_insee = t_avg.code_insee
            WHERE v.indicateur_cfe_m2 IS NOT NULL AND v.indicateur_cfe_m2 > 0";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->execute();
    $row = $stmt->fetch();
    Flight::json([(float)$row['vmin'], (float)$row['vmax']]);
});

Flight::route('GET /api/cfe/departements', function () {
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat || !preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie requise'], 400); return; }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                   ROUND(AVG(v.indicateur_cfe_m2 * t_avg.tarif)::numeric,4) AS cfe_estime,
                   ROUND(AVG(t_avg.tarif)::numeric,2) AS tarif_moyen,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom,0.01),4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN cfe_calcul v ON LEFT(v.code_insee,2) = d.code_insee
            JOIN (
                SELECT left(s.code_insee,5) AS code_insee, AVG(t.$col::numeric) AS tarif
                FROM sections_2025 s
                JOIN tarifs_pivot t
                  ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
                 AND t.num_secteur = s.secteur AND t.categorie = :cat
                WHERE t.$col IS NOT NULL
                GROUP BY left(s.code_insee,5)
            ) t_avg ON v.code_insee = t_avg.code_insee
            WHERE v.indicateur_cfe_m2 IS NOT NULL
            GROUP BY d.code_insee, d.nom_officiel, d.geom";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

Flight::route('GET /api/cfe', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat || !preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie requise'], 400); return; }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $sql = "SELECT v.code_insee, v.libcom, v.millesime,
                   v.taux_cfe_total, v.coeff_neut_com, v.indicateur_cfe_m2,
                   ROUND((v.indicateur_cfe_m2 * t_avg.tarif)::numeric,4) AS cfe_estime,
                   ROUND(t_avg.tarif::numeric,2) AS tarif_section,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(v.geom,0.0002),4)::text AS geojson
            FROM cfe_calcul v
            JOIN (
                SELECT left(s.code_insee,5) AS code_insee, AVG(t.$col::numeric) AS tarif
                FROM sections_2025 s
                JOIN tarifs_pivot t
                  ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
                 AND t.num_secteur = s.secteur AND t.categorie = :cat
                WHERE t.$col IS NOT NULL
                GROUP BY left(s.code_insee,5)
            ) t_avg ON v.code_insee = t_avg.code_insee
            WHERE ST_Intersects(v.geom, ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326))
              AND v.indicateur_cfe_m2 IS NOT NULL
            LIMIT 2000";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── Sections cadastrales ──────────────────────────────────
// Niveau section (zoom ≥ 13) — polygones bruts par bbox
Flight::route('GET /api/sections', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT ogc_fid, code_dep, code_insee, nom_com, section, secteur,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_Transform(geom,4326),0.00005),5) AS geojson
            FROM sections_2025
            WHERE " . bboxWhere('geom') . " LIMIT 3000";
    $stmt = $db->prepare($sql);
    bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// Niveau commune (zoom 9–13) — union des sections par commune + secteur dominant
Flight::route('GET /api/sections/communes', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT left(code_insee,5) AS code_insee, code_dep, nom_com,
                   COUNT(*) AS nb_sections,
                   MODE() WITHIN GROUP (ORDER BY secteur::int) AS secteur_dom,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(
                       ST_Transform(ST_Union(geom),4326), 0.0002),4) AS geojson
            FROM sections_2025
            WHERE " . bboxWhere('geom') . "
            GROUP BY left(code_insee,5), code_dep, nom_com
            LIMIT 1500";
    $stmt = $db->prepare($sql);
    bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// Niveau département (zoom < 9) — géométries des départements + secteur dominant
Flight::route('GET /api/sections/departements', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                   stats.nb_communes, stats.secteur_dom,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom, 0.01),4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN (
                SELECT code_dep,
                       COUNT(DISTINCT left(code_insee,5)) AS nb_communes,
                       MODE() WITHIN GROUP (ORDER BY secteur::int) AS secteur_dom
                FROM sections_2025 GROUP BY code_dep
            ) stats ON stats.code_dep = d.code_insee";
    $stmt = $db->query($sql);
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── Coefficients de localisation ──────────────────────────
Flight::route('GET /api/coeff', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $sql = "SELECT c.ogc_fid, c.idu, c.codecommune, COALESCE(cg.nom, c.codecommune) AS nom_commune,
                   c.section, c.parcelle,
                   c.coeff_2017, c.coeff_2018, c.coeff_2019, c.coeff_2020,
                   c.coeff_2024, c.coeff_2026, c.evolution,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_Transform(c.geom,4326),0.00001),5) AS geojson
            FROM coeff_loc_final c
            LEFT JOIN communes_geom cg ON cg.insee = c.codecommune
            WHERE " . bboxWhere('c.geom') . " LIMIT 4000";
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

Flight::route('GET /api/coeff/stats', function () {
    $champ = validateChamp(Flight::request()->query['champ'] ?? '', COEFF_CHAMPS, 'coeff_2026');
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT MIN($champ::numeric) AS vmin, MAX($champ::numeric) AS vmax
            FROM coeff_loc_final WHERE $champ IS NOT NULL";
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
    ini_set('serialize_precision', 6);
    Flight::json([(float)$row['vmin'], (float)$row['vmax']]);
});

Flight::route('GET /api/coeff/clusters', function () {
    $champ = validateChamp(Flight::request()->query['champ'] ?? '', COEFF_CHAMPS, 'coeff_2026');
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT codecommune, $champ AS valeur, nb_parcelles,
                   ST_AsGeoJSON(ST_Centroid(geom::geometry), 6)::text AS geojson
            FROM coeff_clusters WHERE $champ IS NOT NULL ORDER BY codecommune";
    $stmt = $db->query($sql);
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── Dossiers ──────────────────────────────────────────────
Flight::route('GET /api/dossiers', function () {
    $b     = parseBbox();
    $where = $b
        ? 'WHERE geom IS NOT NULL AND ' . bboxWhere('geom::geometry')
        : 'WHERE geom IS NOT NULL';
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

// ── Tarifs locatifs ───────────────────────────────────────
Flight::route('GET /api/tarifs/categories', function () {
    $db = getDb(); if (!$db) { Flight::json([], 503); return; }
    Flight::json($db->query("SELECT DISTINCT categorie FROM tarifs_pivot ORDER BY categorie")->fetchAll(PDO::FETCH_COLUMN));
});

Flight::route('GET /api/tarifs/stats', function () {
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie invalide'], 400); return; }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT
        percentile_cont(0.000) WITHIN GROUP (ORDER BY $col) AS p0,
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
    Flight::json(array_values(array_map('floatval', $stmt->fetch())));
});

Flight::route('GET /api/tarifs', function () {
    $b   = parseBbox();
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$b || !$cat) { Flight::json(['error' => 'bbox et categorie requis'], 400); return; }
    if (!preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie invalide'], 400); return; }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $sql = "SELECT s.ogc_fid, s.code_dep, s.code_insee, s.nom_com, s.section, s.secteur,
                   t.categorie, t.$col AS valeur,
                   t.val_2017,t.val_2019,t.val_2020,t.val_2021,
                   t.val_2022,t.val_2023,t.val_2024,t.val_2025,t.val_2026,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(ST_Transform(s.geom,4326),0.0001),4) AS geojson
            FROM sections_2025 s
            JOIN tarifs_pivot t
                ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
               AND t.num_secteur = s.secteur AND t.categorie = :cat
            WHERE " . bboxWhere('s.geom') . " LIMIT 5000";
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    bindBbox($stmt, $b);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

Flight::route('GET /api/tarifs/communes', function () {
    $b   = parseBbox();
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$b || !$cat) { Flight::json(['error' => 'bbox et categorie requis'], 400); return; }
    if (!preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie invalide'], 400); return; }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $sql = "SELECT c.insee AS code_insee, left(c.insee,2) AS code_dep,
                   c.nom AS nom_com, t_avg.valeur,
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
    $stmt->bindValue(':ix1', $b[0]); $stmt->bindValue(':iy1', $b[1]);
    $stmt->bindValue(':ix2', $b[2]); $stmt->bindValue(':iy2', $b[3]);
    $stmt->bindValue(':ox1', $b[0]); $stmt->bindValue(':oy1', $b[1]);
    $stmt->bindValue(':ox2', $b[2]); $stmt->bindValue(':oy2', $b[3]);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

Flight::route('GET /api/tarifs/departements', function () {
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat || !preg_match('/^[A-Z]{3}[0-9]$/', $cat)) {
        Flight::json(['error' => 'categorie invalide'], 400); return;
    }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                   t_avg.valeur,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom, 0.01),4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN (
                SELECT dep AS code_dep, ROUND(AVG($col::numeric),2) AS valeur
                FROM tarifs_pivot WHERE categorie = :cat AND $col IS NOT NULL GROUP BY dep
            ) t_avg ON d.code_insee = t_avg.code_dep";
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});
