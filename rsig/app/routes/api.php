<?php
require_once __DIR__ . '/../crm_sync.php';

// ── Statut BDD (admin) ───────────────────────────────────────────────────────
Flight::route('GET /api/db/status', function () {
    requireAdmin();
    $offline = isDbOffline();
    $canConnect = false;
    if (!$offline) {
        try { new PDO(DB_DSN, DB_USER, DB_PASS); $canConnect = true; } catch (PDOException) {}
    }
    Flight::json(['offline' => $offline, 'reachable' => $canConnect]);
});

Flight::route('POST /api/db/offline', function () {
    requireAdmin();
    if (!file_exists(DB_FLAG_PATH)) {
        file_put_contents(DB_FLAG_PATH, date('Y-m-d H:i:s'));
    }
    Flight::json(['offline' => true]);
});

Flight::route('POST /api/db/online', function () {
    requireAdmin();
    if (file_exists(DB_FLAG_PATH)) unlink(DB_FLAG_PATH);
    Flight::json(['offline' => false]);
});

// ── Sync CRM → miroir PostgreSQL (lance en arrière-plan) ──
Flight::route('POST /api/crm/sync', function () {
    $db = getDb();
    if (!$db) { Flight::json(['error' => 'Base non disponible'], 503); return; }

    // Marquer les syncs bloquées (running depuis >30 min) comme erreur
    $db->exec("UPDATE crm_sync_log SET finished_at=now(), status='error', message='Timeout — processus arrêté'
               WHERE status='running' AND started_at < now() - interval '30 minutes'");

    // Vérifie qu'une sync n'est pas déjà en cours (fenêtre 30 min)
    $running = $db->query("SELECT id FROM crm_sync_log WHERE status='running' AND started_at > now() - interval '30 minutes'")->fetchColumn();
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

// ── GeoJSON dossiers — table crm_dossiers unifiée, fallback dossier_acc_geo ─
Flight::route('GET /api/crm/geojson', function () {
    $db = getDb();
    if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }

    $hasTable = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='crm_dossiers'")->fetchColumn();
    $count    = $hasTable ? (int)$db->query("SELECT COUNT(*) FROM crm_dossiers")->fetchColumn() : 0;

    if ($count > 0) {
        $sql = "SELECT
                    numero           AS dossier,
                    reference_client AS rtx_code,
                    client_name,
                    account_rtx_code,
                    account_cp,
                    auditeur,
                    produit,
                    phase,
                    etat,
                    date_demande,
                    date_remise,
                    date_preetudie,
                    adresse,
                    adresse_norm,
                    ville,
                    code_postal,
                    code_insee       AS insee,
                    section,
                    parcelle,
                    lot,
                    montant_tf,
                    type_activite,
                    ST_AsGeoJSON(geom, 6)::text AS geojson
                FROM crm_dossiers
                WHERE geom IS NOT NULL";
    } else {
        // Fallback : table dossier_acc_geo
        $sql = "SELECT
                    dossier,
                    rtx_code,
                    name                               AS client_name,
                    NULL::text                         AS account_rtx_code,
                    NULL::text                         AS account_cp,
                    NULL::text                         AS auditeur,
                    NULL::text                         AS produit,
                    NULL::text                         AS phase,
                    NULL::text                         AS etat,
                    NULL::date                         AS date_demande,
                    NULL::date                         AS date_remise,
                    NULL::date                         AS date_preetudie,
                    adresse_complete                   AS adresse,
                    NULL::text                         AS adresse_norm,
                    NULL::text                         AS ville,
                    cp                                 AS code_postal,
                    insee,
                    section,
                    NULL::text                         AS parcelle,
                    lot,
                    apo_montanttaxefonciere            AS montant_tf,
                    NULL::text                         AS type_activite,
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
        error_log('[rsig] SQL error: ' . $e->getMessage());
        Flight::json(['error' => 'Erreur base de données'], 500);
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
        error_log('[rsig] SQL error: ' . $e->getMessage());
        Flight::json(['error' => 'Erreur base de données'], 500);
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
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'])) {
        Flight::json(['error' => 'Type MIME invalide : ' . $mime], 400); return;
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
    $cacheKey  = "taux_dept_{$champ}_{$millesime}";
    if ($cached = cacheGet($cacheKey)) { Flight::json($cached); return; }
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
    $result = ['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)];
    cacheSet($cacheKey, $result);
    Flight::json($result);
});

// ── Prospects coeff localisation ─────────────────────────────────────────

Flight::route('GET /api/prospects', function () {
    if (!isAdmin()) { Flight::json(['error' => 'Accès refusé'], 403); return; }
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT
                idu, denomination, numero_siren, forme_juridique_abregee,
                adresse, codecommune AS code_insee,
                section, parcelle,
                round(coeff_2017::numeric, 3) AS coeff_2017,
                round(coeff_2024::numeric, 3) AS coeff_2024,
                round(((coeff_2024 - coeff_2017) / NULLIF(coeff_2017, 0) * 100)::numeric) AS evol_pct,
                round(area_batiments::numeric) AS surface_bati_m2,
                array_to_string(usages_bat, ' | ') AS usages,
                ST_AsGeoJSON(ST_Transform(ST_Centroid(geom), 4326), 6)::text AS geojson
            FROM coeff_pm_bat_final
            WHERE coeff_2017 IS NOT NULL AND coeff_2024 IS NOT NULL
              AND coeff_2024 > coeff_2017
              AND area_batiments > 500
              AND nature_culture = 'S'
              AND code_forme_juridique NOT IN (7210,7220,7113,7313,7346,7229,7344,4140,4110,7389,7348,7490,9900)
              AND NOT ('Industriel' = ANY(usages_bat))
            ORDER BY ((coeff_2024 - coeff_2017) / NULLIF(coeff_2017, 0)) DESC";
    $stmt = $db->query($sql);
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

Flight::route('GET /api/prospects/occupants', function () {
    if (!isAdmin()) { Flight::json(['error' => 'Accès refusé'], 403); return; }
    $idu = preg_replace('/[^A-Za-z0-9]/', '', Flight::request()->query['idu'] ?? '');
    if (!$idu) { Flight::json([], 400); return; }
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT
                e.siret, e.siren,
                COALESCE(e.denominationusuelleetablissement, e.enseigne1etablissement) AS nom,
                e.activiteprincipaleetablissement AS naf,
                e.adresse,
                e.codepostaletablissement AS cp,
                e.libellecommuneetablissement AS ville,
                CASE e.trancheeffectifsetablissement
                    WHEN 'NN' THEN NULL  WHEN '00' THEN '0'   WHEN '01' THEN '1-2'
                    WHEN '02' THEN '3-5' WHEN '03' THEN '6-9'  WHEN '11' THEN '10-19'
                    WHEN '12' THEN '20-49' WHEN '21' THEN '50-99' WHEN '22' THEN '100-199'
                    WHEN '31' THEN '200-249' WHEN '32' THEN '250-499' WHEN '41' THEN '500-999'
                    WHEN '42' THEN '1000-1999' ELSE NULL
                END AS effectifs
            FROM coeff_pm_bat_final c
            JOIN etablisement_siren_geo e ON ST_Within(e.geom, c.geom)
            WHERE c.idu = :idu
              AND e.etatadministratifetablissement = 'A'
              AND LEFT(e.activiteprincipaleetablissement, 2) NOT IN (
                  '01','02','03','05','06','07','08','09',
                  '10','11','12','13','14','15','16','17','18','19',
                  '20','21','22','23','24','25','26','27','28','29',
                  '30','31','32','33'
              )
            ORDER BY e.trancheeffectifsetablissement DESC NULLS LAST";
    $stmt = $db->prepare($sql);
    $stmt->execute([':idu' => $idu]);
    Flight::json($stmt->fetchAll(PDO::FETCH_ASSOC));
});

// Évolution d'un taux entre deux millésimes — retourne un GeoJSON communes/dep avec le delta
Flight::route('GET /api/taux/evolution', function () {
    $champ  = validateChamp(Flight::request()->query['champ'] ?? '', TAUX_CHAMPS, 'taux_fb_commune_vote');
    $milDe  = validateMillesime(Flight::request()->query['de']   ?? '2021');
    $milA   = validateMillesime(Flight::request()->query['a']    ?? '2025');
    $level  = Flight::request()->query['level'] ?? 'commune';
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }

    if ($level === 'dept') {
        $cacheKey = "taux_evol_dept_{$champ}_{$milDe}_{$milA}";
        if ($cached = cacheGet($cacheKey)) { Flight::json($cached); return; }
        $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                       ROUND((AVG(t2.$champ::numeric) - AVG(t1.$champ::numeric))::numeric, 4) AS delta,
                       ROUND(AVG(t1.$champ::numeric)::numeric, 4) AS val_de,
                       ROUND(AVG(t2.$champ::numeric)::numeric, 4) AS val_a,
                       ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom, 0.01),4)::text AS geojson
                FROM departements_geom_4326 d
                JOIN taux_clean t1 ON lpad(t1.dep,2,'0') = d.code_insee AND t1.millesime = :milDe AND t1.$champ IS NOT NULL
                JOIN taux_clean t2 ON t2.dep = t1.dep AND t2.com = t1.com AND t2.millesime = :milA AND t2.$champ IS NOT NULL
                GROUP BY d.code_insee, d.nom_officiel, d.geom";
        $stmt = $db->prepare($sql);
        $stmt->execute([':milDe' => $milDe, ':milA' => $milA]);
        $result = ['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)];
        cacheSet($cacheKey, $result, 600);
        Flight::json($result);
    } else {
        $b = parseBbox();
        $conds = ["t1.millesime = :milDe", "t2.millesime = :milA", "t1.$champ IS NOT NULL", "t2.$champ IS NOT NULL"];
        if ($b) $conds[] = "ST_Intersects(ST_SetSRID(t1.geom,4326), ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326))";
        $where = 'WHERE ' . implode(' AND ', $conds);
        $sql = "SELECT t1.ogc_fid, t1.dep, t1.com, t1.libcom, t1.millesime AS millesime_de, t2.millesime AS millesime_a,
                       t1.$champ AS val_de, t2.$champ AS val_a,
                       ROUND((t2.$champ::numeric - t1.$champ::numeric)::numeric, 4) AS delta,
                       ST_AsGeoJSON(ST_SetSRID(t1.geom,4326),6)::text AS geojson
                FROM taux_clean t1
                JOIN taux_clean t2 ON t2.dep = t1.dep AND t2.com = t1.com AND t2.millesime = :milA
                $where LIMIT 5000";
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':milDe', $milDe);
        $stmt->bindValue(':milA',  $milA);
        if ($b) bindBbox($stmt, $b);
        $stmt->execute();
        Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
    }
});

// Benchmark d'une commune : rang dans le département + médiane dep + évolution
Flight::route('GET /api/taux/benchmark', function () {
    $champ     = validateChamp(Flight::request()->query['champ'] ?? '', TAUX_CHAMPS, 'taux_fb_commune_vote');
    $millesime = validateMillesime(Flight::request()->query['millesime'] ?? '2025');
    $dep       = preg_replace('/[^0-9A-Za-z]/', '', Flight::request()->query['dep'] ?? '');
    $com       = preg_replace('/[^0-9]/', '', Flight::request()->query['com'] ?? '');
    if (!$dep || !$com) { Flight::json(['error' => 'dep et com requis'], 400); return; }
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }

    // Valeur de la commune + stats département
    $sql = "WITH dep_vals AS (
                SELECT com, $champ::numeric AS val
                FROM taux_clean
                WHERE dep = :dep AND millesime = :mil AND $champ IS NOT NULL
            ),
            stats AS (
                SELECT
                    COUNT(*) AS nb_communes,
                    ROUND(percentile_cont(0.5) WITHIN GROUP (ORDER BY val)::numeric, 4) AS mediane,
                    ROUND(AVG(val)::numeric, 4) AS moyenne,
                    ROUND(MIN(val)::numeric, 4) AS min_val,
                    ROUND(MAX(val)::numeric, 4) AS max_val
                FROM dep_vals
            ),
            rang AS (
                SELECT com,
                       RANK() OVER (ORDER BY val DESC) AS rang_desc
                FROM dep_vals
            )
            SELECT s.nb_communes, s.mediane, s.moyenne, s.min_val, s.max_val,
                   r.rang_desc,
                   ROUND(r.rang_desc::numeric / s.nb_communes * 100, 1) AS pct_rang,
                   d.val AS val_commune
            FROM stats s
            JOIN dep_vals d ON d.com = :com
            JOIN rang r ON r.com = :com";
    $stmt = $db->prepare($sql);
    $stmt->execute([':dep' => $dep, ':mil' => $millesime, ':com' => $com]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { Flight::json(['error' => 'commune non trouvée'], 404); return; }

    // Évolution sur toutes les années disponibles
    $sqlEvol = "SELECT millesime, ROUND($champ::numeric, 4) AS val
                FROM taux_clean WHERE dep = :dep AND com = :com AND $champ IS NOT NULL
                ORDER BY millesime";
    $stmtE = $db->prepare($sqlEvol);
    $stmtE->execute([':dep' => $dep, ':com' => $com]);
    $evol = $stmtE->fetchAll(PDO::FETCH_ASSOC);

    Flight::json(array_merge($row, ['evolution' => $evol]));
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
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat || !preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie requise'], 400); return; }
    $annee    = validateAnnee(Flight::request()->query['annee'] ?? '');
    $cacheKey = "cfe_dept_{$cat}_{$annee}";
    if ($cached = cacheGet($cacheKey)) { Flight::json($cached); return; }
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $col   = "val_$annee";
    $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                   ROUND(AVG(v.indicateur_cfe_m2 * t_avg.tarif)::numeric,4) AS cfe_estime,
                   ROUND(AVG(t_avg.tarif)::numeric,2) AS tarif_moyen,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom,0.01),4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN cfe_calcul v ON LPAD(LEFT(v.code_insee,2),2,'0') = d.code_insee
            JOIN (
                SELECT left(s.code_insee,5) AS code_insee, AVG(t.$col::numeric) AS tarif
                FROM sections_2025 s
                JOIN tarifs_pivot t
                  ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
                 AND t.num_secteur = s.secteur AND t.categorie = :cat
                WHERE t.$col IS NOT NULL
                GROUP BY left(s.code_insee,5)
            ) t_avg ON LPAD(v.code_insee,5,'0') = t_avg.code_insee
            WHERE v.indicateur_cfe_m2 IS NOT NULL
            GROUP BY d.code_insee, d.nom_officiel, d.geom";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->execute();
    $result = ['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)];
    cacheSet($cacheKey, $result);
    Flight::json($result);
});

Flight::route('GET /api/cfe', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat || !preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie requise'], 400); return; }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $sql = "SELECT s.code_insee, s.section, s.secteur, s.nom_com AS libcom,
                   v.millesime, v.taux_cfe_total, v.coeff_neut_com, v.indicateur_cfe_m2,
                   ROUND((v.indicateur_cfe_m2 * t.$col::numeric)::numeric,4) AS cfe_estime,
                   ROUND(t.$col::numeric,2) AS tarif_section,
                   ST_AsGeoJSON(ST_Transform(ST_SimplifyPreserveTopology(s.geom,2),4326),4)::text AS geojson
            FROM sections_2025 s
            JOIN tarifs_pivot t
                ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
               AND t.num_secteur = s.secteur AND t.categorie = :cat AND t.$col IS NOT NULL
            JOIN cfe_calcul v ON LPAD(v.code_insee,5,'0') = s.code_insee
            WHERE ST_Intersects(s.geom, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),2154))
              AND v.indicateur_cfe_m2 IS NOT NULL
            LIMIT 5000";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── TF estimée €/m² ──────────────────────────────────────
Flight::route('GET /api/tf/departements', function () {
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat || !preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie requise'], 400); return; }
    $annee    = validateAnnee(Flight::request()->query['annee'] ?? '');
    $cacheKey = "tf_dept_{$cat}_{$annee}";
    if ($cached = cacheGet($cacheKey)) { Flight::json($cached); return; }
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $col   = "val_$annee";
    $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                   ROUND(AVG(v.indicateur_tf_m2 * t_avg.tarif)::numeric,4) AS tf_estime,
                   ROUND(AVG(t_avg.tarif)::numeric,2) AS tarif_moyen,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom,0.01),4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN tf_calcul v ON LPAD(LEFT(v.code_insee,2),2,'0') = d.code_insee AND v.millesime = :annee
            JOIN (
                SELECT left(s.code_insee,5) AS code_insee, AVG(t.$col::numeric) AS tarif
                FROM sections_2025 s
                JOIN tarifs_pivot t
                  ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
                 AND t.num_secteur = s.secteur AND t.categorie = :cat
                WHERE t.$col IS NOT NULL
                GROUP BY left(s.code_insee,5)
            ) t_avg ON v.code_insee = t_avg.code_insee
            WHERE v.indicateur_tf_m2 IS NOT NULL AND v.indicateur_tf_m2 > 0
            GROUP BY d.code_insee, d.nom_officiel, d.geom";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->bindValue(':annee', (int)$annee, PDO::PARAM_INT);
    $stmt->execute();
    $result = ['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)];
    cacheSet($cacheKey, $result);
    Flight::json($result);
});

Flight::route('GET /api/tf', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $cat = Flight::request()->query['categorie'] ?? '';
    if (!$cat || !preg_match('/^[A-Z]{3}[0-9]$/', $cat)) { Flight::json(['error' => 'categorie requise'], 400); return; }
    $annee = validateAnnee(Flight::request()->query['annee'] ?? '');
    $col   = "val_$annee";
    $sql = "SELECT s.code_insee, s.section, s.secteur, s.nom_com AS libcom,
                   v.millesime, v.taux_tf_total, v.taux_com, v.taux_synd,
                   v.taux_epci, v.taux_tse, v.taux_gemapi, v.taux_tasa,
                   v.taux_teom, v.indicateur_tf_m2,
                   ROUND((v.indicateur_tf_m2 * t.$col::numeric)::numeric,4) AS tf_estime,
                   ROUND(t.$col::numeric,2) AS tarif_section,
                   ST_AsGeoJSON(ST_Transform(ST_SimplifyPreserveTopology(s.geom,2),4326),4)::text AS geojson
            FROM sections_2025 s
            JOIN tarifs_pivot t
                ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
               AND t.num_secteur = s.secteur AND t.categorie = :cat AND t.$col IS NOT NULL
            JOIN tf_calcul v ON v.code_insee = s.code_insee AND v.millesime = :annee
            WHERE ST_Intersects(s.geom, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),2154))
              AND v.indicateur_tf_m2 IS NOT NULL AND v.indicateur_tf_m2 > 0
            LIMIT 5000";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->bindValue(':annee', (int)$annee, PDO::PARAM_INT);
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── TSB — Circonscriptions IDF + PACA ────────────────────
// ── Taxe d'Aménagement — mise à jour ─────────────────────
Flight::route('GET /api/ta/update/status', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $row    = $db->query("SELECT * FROM ta_update_log ORDER BY id DESC LIMIT 1")->fetch();
    $counts = $db->query("SELECT COUNT(*) FROM ta_taux")->fetchColumn();
    $deps   = $db->query("SELECT COUNT(*) FROM ta_taux WHERE taux_dep IS NOT NULL")->fetchColumn();
    $reg    = $db->query("SELECT COUNT(*) FROM ta_taux WHERE taux_reg IS NOT NULL")->fetchColumn();
    $last   = $db->query("SELECT MAX(date_effet) FROM ta_taux")->fetchColumn();
    Flight::json([
        'last_run'    => $row ?: null,
        'communes'    => (int)$counts,
        'avec_dep'    => (int)$deps,
        'avec_reg'    => (int)$reg,
        'date_effet_max' => $last,
    ]);
});

Flight::route('POST /api/ta/update', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }

    $running = $db->query("SELECT id FROM ta_update_log WHERE status='running' AND started_at > now() - interval '15 minutes'")->fetchColumn();
    if ($running) { Flight::json(['status' => 'already_running', 'log_id' => $running]); return; }

    $logStmt = $db->prepare("INSERT INTO ta_update_log (started_at, status, message) VALUES (now(),'running','') RETURNING id");
    $logStmt->execute();
    $logId = (int)$logStmt->fetchColumn();

    $python = PYTHON_PATH;
    $script = SCRIPTS_PATH . '/update_ta.py';
    $log    = SCRIPTS_PATH . '/update_ta_' . $logId . '.log';

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = '"' . $python . '" "' . $script . '" >"' . $log . '" 2>&1';
        pclose(popen('start "" /B cmd /c "' . $cmd . '"', 'r'));
    } else {
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' >' . escapeshellarg($log) . ' 2>&1 &';
        shell_exec($cmd);
    }

    // Worker PHP qui surveille la fin du process et met à jour le log
    $phpWorker = realpath(__DIR__ . '/../ta_worker.php');
    $phpCmd = '"' . PHP_CLI_PATH . '"' . ' "' . $phpWorker . '" ' . $logId . ' "' . addslashes($log) . '"';
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start "" /B ' . $phpCmd . ' >NUL 2>&1', 'r'));
    } else {
        shell_exec(escapeshellarg(PHP_CLI_PATH) . ' ' . escapeshellarg($phpWorker) . ' ' . $logId . ' ' . escapeshellarg($log) . ' >/dev/null 2>&1 &');
    }

    Flight::json(['status' => 'started', 'log_id' => $logId]);
});

// ── Taxe d'Aménagement ───────────────────────────────────
Flight::route('GET /api/ta/exo', function () {
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $dep = trim(Flight::request()->query['dep']  ?? '');
    $com = trim(Flight::request()->query['com']  ?? '');
    $exoOnly = (Flight::request()->query['exo_only'] ?? '1') === '1';

    $where = ['1=1'];
    $params = [];
    if ($dep) { $where[] = 'dep = :dep'; $params[':dep'] = str_pad($dep, 2, '0', STR_PAD_LEFT); }
    if ($com) { $where[] = 'UPPER(libcom) LIKE :com'; $params[':com'] = '%' . strtoupper($com) . '%'; }
    if ($exoOnly) {
        $where[] = "(exo_habitation IS NOT NULL OR exo_industriel IS NOT NULL OR exo_commerce IS NOT NULL
                     OR exo_immeubles_classes IS NOT NULL OR exo_abris_jardin IS NOT NULL
                     OR exo_maisons_sante IS NOT NULL OR exo_terrains_rehab IS NOT NULL
                     OR exo_transf_habitation IS NOT NULL OR exo_pret_ptx IS NOT NULL
                     OR val_forfait_station IS NOT NULL)";
    }

    $sql = "SELECT code_insee, libcom, dep, taux_com, taux_dep, taux_reg,
                   ROUND(COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0),3) AS taux_total,
                   date_effet, val_forfait_station,
                   exo_habitation, exo_pret_ptx, exo_industriel, exo_commerce,
                   exo_immeubles_classes, exo_abris_jardin, exo_maisons_sante,
                   exo_terrains_rehab, exo_transf_habitation
            FROM ta_taux
            WHERE " . implode(' AND ', $where) . "
            ORDER BY dep, libcom
            LIMIT 500";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    Flight::json($stmt->fetchAll());
});

Flight::route('GET /api/ta/stats', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    // Quintiles précalculés sur l'ensemble des communes (toutes années confondues)
    $row = $db->query("
        SELECT
            ROUND(PERCENTILE_CONT(0.0)  WITHIN GROUP (ORDER BY COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))::numeric,2) AS t0,
            ROUND(PERCENTILE_CONT(0.2)  WITHIN GROUP (ORDER BY COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))::numeric,2) AS t20,
            ROUND(PERCENTILE_CONT(0.4)  WITHIN GROUP (ORDER BY COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))::numeric,2) AS t40,
            ROUND(PERCENTILE_CONT(0.6)  WITHIN GROUP (ORDER BY COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))::numeric,2) AS t60,
            ROUND(PERCENTILE_CONT(0.8)  WITHIN GROUP (ORDER BY COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))::numeric,2) AS t80,
            ROUND(PERCENTILE_CONT(1.0)  WITHIN GROUP (ORDER BY COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))::numeric,2) AS t100,
            -- Estimations €/m² logement (IDF : forfait 900, FRANCE : 886 pour 2025)
            ROUND(PERCENTILE_CONT(0.0)  WITHIN GROUP (ORDER BY CASE WHEN dep IN ('75','77','78','91','92','93','94','95') THEN 900 ELSE 886 END * (COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))/100)::numeric,2) AS e0,
            ROUND(PERCENTILE_CONT(0.2)  WITHIN GROUP (ORDER BY CASE WHEN dep IN ('75','77','78','91','92','93','94','95') THEN 900 ELSE 886 END * (COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))/100)::numeric,2) AS e20,
            ROUND(PERCENTILE_CONT(0.4)  WITHIN GROUP (ORDER BY CASE WHEN dep IN ('75','77','78','91','92','93','94','95') THEN 900 ELSE 886 END * (COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))/100)::numeric,2) AS e40,
            ROUND(PERCENTILE_CONT(0.6)  WITHIN GROUP (ORDER BY CASE WHEN dep IN ('75','77','78','91','92','93','94','95') THEN 900 ELSE 886 END * (COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))/100)::numeric,2) AS e60,
            ROUND(PERCENTILE_CONT(0.8)  WITHIN GROUP (ORDER BY CASE WHEN dep IN ('75','77','78','91','92','93','94','95') THEN 900 ELSE 886 END * (COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))/100)::numeric,2) AS e80,
            ROUND(PERCENTILE_CONT(1.0)  WITHIN GROUP (ORDER BY CASE WHEN dep IN ('75','77','78','91','92','93','94','95') THEN 900 ELSE 886 END * (COALESCE(taux_com,0)+COALESCE(taux_dep,0)+COALESCE(taux_reg,0))/100)::numeric,2) AS e100
        FROM ta_taux WHERE taux_com > 0
    ")->fetch();
    Flight::json([
        'taux_total' => [(float)$row['t0'],(float)$row['t20'],(float)$row['t40'],(float)$row['t60'],(float)$row['t80'],(float)$row['t100']],
        'estime'     => [(float)$row['e0'],(float)$row['e20'],(float)$row['e40'],(float)$row['e60'],(float)$row['e80'],(float)$row['e100']],
    ]);
});

Flight::route('GET /api/ta/forfaitaires', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $annee = (int)(Flight::request()->query['annee'] ?? 0);
    if (!$annee) $annee = (int)$db->query("SELECT MAX(annee) FROM ta_forfaitaires")->fetchColumn();
    $stmt = $db->prepare("SELECT annee, zone, type_local, valeur FROM ta_forfaitaires WHERE annee=:a ORDER BY zone, type_local");
    $stmt->bindValue(':a', $annee, PDO::PARAM_INT);
    $stmt->execute();
    Flight::json(['annee' => $annee, 'forfaitaires' => $stmt->fetchAll()]);
});

// ── TA union spatiale : commune découpée par zones majorées ─
Flight::route('GET /api/ta/union', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $db    = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $annee = (int)(Flight::request()->query['annee'] ?? 0);
    $milM  = (int)(Flight::request()->query['millesime'] ?? 0);
    if (!$annee) $annee = (int)$db->query("SELECT MAX(annee) FROM ta_forfaitaires")->fetchColumn();
    if (!$milM)  $milM  = 2026;   // millésime zones majorées

    $DEPS_IDF = ['75','77','78','91','92','93','94','95'];
    $forf = $db->prepare("SELECT zone, type_local, valeur FROM ta_forfaitaires WHERE annee=:a");
    $forf->bindValue(':a', $annee, PDO::PARAM_INT);
    $forf->execute();
    $forfMap = [];
    foreach ($forf->fetchAll() as $f) $forfMap[$f['zone']][$f['type_local']] = (float)$f['valeur'];

    // CTE : zones majorées dédupliquées par section (le plus récent millesime <= $milM)
    // + zones majorées par parcelle (si disponibles)
    $sql = "
    WITH bbox_geom AS (
        SELECT ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326) AS b
    ),
    -- Sections majorées (dédupliquées)
    maj_sect AS (
        SELECT DISTINCT ON (code_insee, section)
            code_insee, section, NULL::text AS parcelle,
            taux, date_effet, millesime,
            ST_MakeValid(geom) AS geom
        FROM ta_majore_sections
        WHERE millesime <= :mil AND ST_Intersects(geom,(SELECT b FROM bbox_geom))
        ORDER BY code_insee, section, millesime DESC, taux DESC
    ),
    -- Parcelles majorées (dédupliquées, si table peuplée)
    maj_parc AS (
        SELECT DISTINCT ON (code_insee, section, parcelle)
            code_insee, section, parcelle,
            taux, date_effet, millesime,
            ST_MakeValid(geom) AS geom
        FROM ta_majore_parcelles
        WHERE millesime <= :mil AND ST_Intersects(geom,(SELECT b FROM bbox_geom))
        ORDER BY code_insee, section, parcelle, millesime DESC, taux DESC
    ),
    -- Toutes les zones majorées : parcelles en priorité, sections si pas de parcelle
    maj_all AS (
        SELECT code_insee, section, parcelle, taux, date_effet, millesime, geom FROM maj_parc
        UNION ALL
        SELECT s.code_insee, s.section, NULL, s.taux, s.date_effet, s.millesime, s.geom
        FROM maj_sect s
        WHERE NOT EXISTS (
            SELECT 1 FROM maj_parc p WHERE p.code_insee=s.code_insee AND p.section=s.section
        )
    ),
    -- Union des zones majorées par commune
    maj_union AS (
        SELECT code_insee,
               ST_Union(geom) AS geom_maj
        FROM maj_all
        GROUP BY code_insee
    ),
    -- Communes dans la bbox avec taux
    communes_bbox AS (
        SELECT t.code_insee, t.libcom, t.dep, t.taux_com, t.taux_dep, t.taux_reg,
               ROUND(COALESCE(t.taux_com,0)+COALESCE(t.taux_dep,0)+COALESCE(t.taux_reg,0),3) AS taux_total,
               CASE WHEN ST_SRID(c.geom)=4326 THEN ST_MakeValid(c.geom)
                    ELSE ST_MakeValid(ST_Transform(c.geom,4326)) END AS geom
        FROM ta_taux t
        JOIN communes_geom c ON c.insee=t.code_insee
        WHERE ST_Intersects(
            CASE WHEN ST_SRID(c.geom)=4326 THEN c.geom ELSE ST_Transform(c.geom,4326) END,
            (SELECT b FROM bbox_geom))
    )
    -- OUTPUT : zones non majorées (reste commune)
    SELECT cb.code_insee, cb.libcom, cb.dep,
           cb.taux_com AS taux_zone, cb.taux_dep, cb.taux_reg, cb.taux_total,
           'commune' AS type_zone, NULL AS section, NULL AS parcelle,
           ST_AsGeoJSON(ST_SimplifyPreserveTopology(
               CASE WHEN mu.geom_maj IS NOT NULL
                    THEN ST_Difference(cb.geom, mu.geom_maj)
                    ELSE cb.geom END, 0.0001),4)::text AS geojson
    FROM communes_bbox cb
    LEFT JOIN maj_union mu ON mu.code_insee = cb.code_insee
    WHERE cb.taux_total > 0

    UNION ALL

    -- Zones majorées individuelles
    SELECT ma.code_insee,
           cb.libcom, cb.dep,
           ma.taux AS taux_zone, cb.taux_dep, cb.taux_reg,
           ROUND(ma.taux + COALESCE(cb.taux_dep,0) + COALESCE(cb.taux_reg,0), 3) AS taux_total,
           CASE WHEN ma.parcelle IS NOT NULL THEN 'parcelle' ELSE 'section' END AS type_zone,
           ma.section, ma.parcelle,
           ST_AsGeoJSON(ST_SimplifyPreserveTopology(ma.geom,0.0001),4)::text AS geojson
    FROM maj_all ma
    JOIN communes_bbox cb ON cb.code_insee = ma.code_insee
    ";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->bindValue(':mil', $milM, PDO::PARAM_INT);
    $stmt->execute();

    $features = [];
    foreach ($stmt as $row) {
        $raw = $row['geojson'];
        if (is_resource($raw)) $raw = stream_get_contents($raw);
        if (!$raw) continue;
        $g = json_decode((string)$raw, true);
        if (!$g || empty($g['coordinates'])) continue;
        unset($row['geojson']);
        $dep   = $row['dep'];
        $zone  = in_array($dep, $DEPS_IDF) ? 'IDF' : 'FRANCE';
        $taux  = (float)($row['taux_total'] ?? 0) / 100;
        $row['forfait_annee'] = $annee;
        $row['forfait_zone']  = $zone;
        $row['ta_estime_log'] = isset($forfMap[$zone]['Locaux à usage de logement'])
            ? round($forfMap[$zone]['Locaux à usage de logement'] * $taux, 2) : null;
        $row['ta_estime_aut'] = isset($forfMap[$zone]['Autres constructions'])
            ? round($forfMap[$zone]['Autres constructions'] * $taux, 2) : null;
        $row['taux_com']   = (float)($row['taux_zone'] ?? 0);
        $row['taux_total'] = (float)($row['taux_total'] ?? 0);
        $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>$row];
    }
    Flight::json(['type'=>'FeatureCollection','features'=>$features]);
});

Flight::route('GET /api/ta', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $db    = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $annee = (int)(Flight::request()->query['annee'] ?? 0);
    if (!$annee) $annee = (int)$db->query("SELECT MAX(annee) FROM ta_forfaitaires")->fetchColumn();
    $DEPS_IDF = ['75','77','78','91','92','93','94','95'];
    // Valeurs forfaitaires pour l'année choisie (logement + autres constructions)
    $forf = $db->prepare("SELECT zone, type_local, valeur FROM ta_forfaitaires WHERE annee=:a");
    $forf->bindValue(':a', $annee, PDO::PARAM_INT);
    $forf->execute();
    $forfMap = [];
    foreach ($forf->fetchAll() as $f) $forfMap[$f['zone']][$f['type_local']] = (float)$f['valeur'];

    $sql = "SELECT t.code_insee, t.libcom, t.dep,
                   t.taux_com, t.taux_dep, t.taux_reg, t.date_effet,
                   ROUND(COALESCE(t.taux_com,0) + COALESCE(t.taux_dep,0) + COALESCE(t.taux_reg,0), 3) AS taux_total,
                   t.val_forfait_station,
                   t.exo_habitation, t.exo_pret_ptx, t.exo_industriel, t.exo_commerce,
                   t.exo_immeubles_classes, t.exo_abris_jardin, t.exo_maisons_sante,
                   t.exo_terrains_rehab, t.exo_transf_habitation,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(
                       CASE WHEN ST_SRID(c.geom)=4326 THEN c.geom ELSE ST_Transform(c.geom,4326) END,
                   0.0002),4)::text AS geojson
            FROM ta_taux t
            JOIN communes_geom c ON c.insee = t.code_insee
            WHERE ST_Intersects(
                CASE WHEN ST_SRID(c.geom)=4326 THEN c.geom ELSE ST_Transform(c.geom,4326) END,
                ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326))
            LIMIT 2000";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->execute();

    // Injecter ta_estime (logement et autres) dans chaque feature
    $features = [];
    foreach ($stmt as $row) {
        $raw = $row['geojson'];
        if (is_resource($raw)) $raw = stream_get_contents($raw);
        $g = json_decode((string)$raw, true);
        unset($row['geojson']);
        $zone  = in_array($row['dep'], $DEPS_IDF) ? 'IDF' : 'FRANCE';
        $taux  = (float)($row['taux_total'] ?? 0) / 100;
        $row['forfait_annee']  = $annee;
        $row['forfait_zone']   = $zone;
        $row['ta_estime_log']  = isset($forfMap[$zone]['Locaux à usage de logement'])
            ? round($forfMap[$zone]['Locaux à usage de logement'] * $taux, 2) : null;
        $row['ta_estime_aut']  = isset($forfMap[$zone]['Autres constructions'])
            ? round($forfMap[$zone]['Autres constructions'] * $taux, 2) : null;
        $features[] = ['type' => 'Feature', 'geometry' => $g, 'properties' => $row];
    }
    Flight::json(['type' => 'FeatureCollection', 'features' => $features]);
});

Flight::route('GET /api/ta/departements', function () {
    $db    = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $annee = (int)(Flight::request()->query['annee'] ?? 0);
    if (!$annee) $annee = (int)$db->query("SELECT MAX(annee) FROM ta_forfaitaires")->fetchColumn();
    $DEPS_IDF = ['75','77','78','91','92','93','94','95'];
    $forf = $db->prepare("SELECT zone, type_local, valeur FROM ta_forfaitaires WHERE annee=:a");
    $forf->bindValue(':a', $annee, PDO::PARAM_INT);
    $forf->execute();
    $forfMap = [];
    foreach ($forf->fetchAll() as $f) $forfMap[$f['zone']][$f['type_local']] = (float)$f['valeur'];

    $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                   ROUND(AVG(COALESCE(t.taux_com,0))::numeric,3) AS taux_com_moyen,
                   MAX(t.taux_dep) AS taux_dep,
                   MAX(t.taux_reg) AS taux_reg,
                   ROUND(AVG(COALESCE(t.taux_com,0) + COALESCE(t.taux_dep,0) + COALESCE(t.taux_reg,0))::numeric,3) AS taux_total_moyen,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom,0.01),4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN ta_taux t ON LPAD(LEFT(t.code_insee,2),2,'0') = d.code_insee
            GROUP BY d.code_insee, d.nom_officiel, d.geom";

    $features = [];
    foreach ($db->query($sql) as $row) {
        $raw = $row['geojson'];
        if (is_resource($raw)) $raw = stream_get_contents($raw);
        $g = json_decode((string)$raw, true);
        unset($row['geojson']);
        $zone = in_array($row['code_dep'], $DEPS_IDF) ? 'IDF' : 'FRANCE';
        $taux = (float)($row['taux_total_moyen'] ?? 0) / 100;
        $row['forfait_annee']  = $annee;
        $row['ta_estime_log']  = isset($forfMap[$zone]['Locaux à usage de logement'])
            ? round($forfMap[$zone]['Locaux à usage de logement'] * $taux, 2) : null;
        $row['ta_estime_aut']  = isset($forfMap[$zone]['Autres constructions'])
            ? round($forfMap[$zone]['Autres constructions'] * $taux, 2) : null;
        $features[] = ['type' => 'Feature', 'geometry' => $g, 'properties' => $row];
    }
    Flight::json(['type' => 'FeatureCollection', 'features' => $features]);
});

// ── TA majorée — sections + centroïds pour clusters ──────
Flight::route('GET /api/ta/majore/millesimes', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    // Union des millésimes sections + parcelles
    $stmt = $db->query("
        SELECT DISTINCT millesime FROM (
            SELECT millesime FROM ta_majore_sections
            UNION
            SELECT millesime FROM ta_majore_parcelles
        ) m ORDER BY millesime DESC
    ");
    Flight::json($stmt->fetchAll(PDO::FETCH_COLUMN));
});

Flight::route('GET /api/ta/majore', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $b  = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }

    $mil = (int)(Flight::request()->query['millesime'] ?? 0);
    $milFilter = $mil > 0 ? "AND millesime = $mil" : "";

    // Taille de la bbox en degrés : grande bbox → on ne sort que des points (centroides)
    // pour éviter de sérialiser des centaines de milliers de polygones
    $bboxW   = abs($b[2] - $b[0]);
    $bboxH   = abs($b[3] - $b[1]);
    $isLarge = ($bboxW > 3 || $bboxH > 2);   // > ~dep entier → mode points

    if ($isLarge) {
        // Mode points : un centroïde léger par commune (ST_Centroid sur un seul geom/commune)
        // Pas de ST_Union → rapide même sur 450k lignes
        $sqlPoints = "SELECT z.code_insee, z.dep, z.libcom, z.taux_max AS taux, z.millesime,
                          ST_AsGeoJSON(z.pt, 5)::text AS geojson
                      FROM (
                          SELECT code_insee, MAX(dep) AS dep, MAX(libcom) AS libcom,
                                 MAX(taux) AS taux_max, MAX(millesime) AS millesime,
                                 ST_Centroid(ST_Collect(ST_PointOnSurface(geom))) AS pt
                          FROM (
                              SELECT code_insee, dep, libcom, taux, millesime, geom
                              FROM ta_majore_parcelles
                              WHERE ST_Intersects(geom, ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326)) $milFilter
                              UNION ALL
                              SELECT code_insee, dep, libcom, taux, millesime, geom
                              FROM ta_majore_sections_latest
                              WHERE ST_Intersects(geom, ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326)) $milFilter
                          ) combined
                          GROUP BY code_insee
                      ) z";

        $stmt = $db->prepare($sqlPoints);
        $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
        $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
        $stmt->execute();
        $features = [];
        foreach ($stmt as $row) {
            $g = json_decode((string)(is_resource($row['geojson']) ? stream_get_contents($row['geojson']) : $row['geojson']), true);
            if (!$g) continue;
            $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>[
                'dep'=>$row['dep'],'code_insee'=>$row['code_insee'],
                'libcom'=>$row['libcom'],'type_zone'=>'commune',
                'taux'=>(float)$row['taux'],'millesime'=>(int)$row['millesime'],
            ]];
        }
        Flight::json(['type'=>'FeatureCollection','features'=>$features,'mode'=>'points']);
        return;
    }

    // Mode polygones (bbox petite) : parcelles en priorité, sections en complément
    $sqlParc = "SELECT DISTINCT ON (code_insee, section, parcelle)
                    dep, commune, code_insee, libcom, prefixe, section, parcelle,
                    taux, date_effet, millesime,
                    ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.00005), 5)::text AS geojson
               FROM ta_majore_parcelles
               WHERE ST_Intersects(geom, ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326)) $milFilter
               ORDER BY code_insee, section, parcelle, millesime DESC, taux DESC";

    $sqlSect = "SELECT dep, commune, s.code_insee, s.libcom, s.prefixe, s.section,
                    NULL::text AS parcelle,
                    s.taux, s.date_effet, s.millesime,
                    ST_AsGeoJSON(s.geom, 4)::text AS geojson
               FROM ta_majore_sections_latest s
               WHERE ST_Intersects(s.geom, ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326)) $milFilter
                 AND NOT EXISTS (
                     SELECT 1 FROM ta_majore_parcelles p
                     WHERE p.code_insee = s.code_insee AND p.section = s.section
                 )";

    $features = [];

    try {
        $stmt = $db->prepare($sqlParc);
        $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
        $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
        $stmt->execute();
        foreach ($stmt as $row) {
            $g = json_decode((string)(is_resource($row['geojson']) ? stream_get_contents($row['geojson']) : $row['geojson']), true);
            if (!$g || empty($g['coordinates'])) continue;
            $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>[
                'dep'=>$row['dep'],'commune'=>$row['commune'],'code_insee'=>$row['code_insee'],
                'libcom'=>$row['libcom'],'prefixe'=>$row['prefixe'],'section'=>$row['section'],
                'parcelle'=>$row['parcelle'],'type_zone'=>'parcelle',
                'taux'=>(float)$row['taux'],'date_effet'=>$row['date_effet'],
                'millesime'=>(int)$row['millesime'],
            ]];
        }
    } catch (\Throwable $e) {}

    $stmt = $db->prepare($sqlSect);
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->execute();
    foreach ($stmt as $row) {
        $g = json_decode((string)(is_resource($row['geojson']) ? stream_get_contents($row['geojson']) : $row['geojson']), true);
        if (!$g || empty($g['coordinates'])) continue;
        $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>[
            'dep'=>$row['dep'],'commune'=>$row['commune'],'code_insee'=>$row['code_insee'],
            'libcom'=>$row['libcom'],'prefixe'=>$row['prefixe'],'section'=>$row['section'],
            'parcelle'=>null,'type_zone'=>'section',
            'taux'=>(float)$row['taux'],'date_effet'=>$row['date_effet'],
            'millesime'=>(int)$row['millesime'],
        ]];
    }

    Flight::json(['type'=>'FeatureCollection','features'=>$features,'mode'=>'polygons']);
});

// ── TA parcelles — appel direct API DGFIP par bbox ────────
Flight::route('GET /api/ta/parcelles', function () {
    $b = parseBbox();
    if (!$b) { Flight::json(['error' => 'bbox requis'], 400); return; }
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }

    // Trouver communes dans la bbox puis appeler DGFIP
    $stmt = $db->prepare("
        SELECT DISTINCT LEFT(code_insee,2) AS dep, code_com AS com
        FROM sections_2025
        WHERE ST_Intersects(geom, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),2154))
        LIMIT 20
    ");
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->execute();
    $communes = $stmt->fetchAll();
    if (!$communes) { Flight::json(['type'=>'FeatureCollection','features'=>[]]); return; }

    // Construire filtre DGFIP
    $filters = array_map(fn($c) => "(departement=\"{$c['dep']}\" AND commune=\"{$c['com']}\")", $communes);
    $where   = 'date_fin is null AND zone_application="Communale" AND taux > 5 AND parcelle is not null AND (' . implode(' OR ', $filters) . ')';
    $apiUrl  = 'https://data.economie.gouv.fr/api/explore/v2.1/catalog/datasets/delta_deliberation_tam_17_01_23/records'
             . '?limit=2000&select=departement%2Ccommune%2Clibelle_commune%2Ctaux%2Cdate_effet%2Cprefixe%2Csection%2Cparcelle'
             . '&' . http_build_query(['where' => $where]);
    $ctx = stream_context_create(['http' => ['timeout'=>15,'user_agent'=>'Mozilla/5.0','ignore_errors'=>true]]);
    $raw = @file_get_contents($apiUrl, false, $ctx);
    if (!$raw) { Flight::json(['type'=>'FeatureCollection','features'=>[]]); return; }
    $data = json_decode($raw, true);
    $results = $data['results'] ?? [];

    // Joindre avec parcelles_all pour la géométrie
    $features = [];
    foreach ($results as $r) {
        $dep  = str_pad($r['departement'], 2, '0', STR_PAD_LEFT);
        $com  = str_pad($r['commune'],     3, '0', STR_PAD_LEFT);
        $pref = $r['prefixe'] ?? '0';
        $sec  = $r['section'] ?? '';
        $parc = $r['parcelle'] ?? '';
        // Code parcelle = dep + com + prefixe(3) + section(2) + parcelle(4)
        $codeParc = $dep . $com . str_pad($pref,3,'0',STR_PAD_LEFT) . str_pad($sec,2,' ',STR_PAD_LEFT) . str_pad($parc,4,'0',STR_PAD_LEFT);
        $gStmt = $db->prepare("SELECT ST_AsGeoJSON(ST_Transform(geom,4326),5)::text FROM parcelles_all WHERE id_parcellaire = :id LIMIT 1");
        $gStmt->bindValue(':id', $codeParc);
        $gStmt->execute();
        $gRow = $gStmt->fetch(PDO::FETCH_COLUMN);
        if (!$gRow) continue;
        $features[] = ['type'=>'Feature','geometry'=>json_decode($gRow,true),'properties'=>[
            'code_insee' => $dep.$com,
            'libcom'     => $r['libelle_commune'],
            'section'    => $sec, 'parcelle' => $parc,
            'taux'       => $r['taux'],
            'date_effet' => $r['date_effet'],
        ]];
    }
    Flight::json(['type'=>'FeatureCollection','features'=>$features]);
});

// ── TASS — Surfaces de stationnement IDF ─────────────────
Flight::route('GET /api/tass/millesimes', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->query("SELECT DISTINCT millesime FROM tass_tarifs ORDER BY millesime DESC");
    Flight::json($stmt->fetchAll(PDO::FETCH_COLUMN));
});

Flight::route('GET /api/tass/tarifs', function () {
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $mil = (int)(Flight::request()->query['millesime'] ?? 0);
    if (!$mil) $mil = (int)$db->query("SELECT MAX(millesime) FROM tass_tarifs")->fetchColumn();
    $stmt = $db->prepare("SELECT circonscription, tarif FROM tass_tarifs WHERE millesime=:m ORDER BY circonscription");
    $stmt->bindValue(':m', $mil, PDO::PARAM_INT);
    $stmt->execute();
    Flight::json(['millesime' => $mil, 'tarifs' => $stmt->fetchAll()]);
});

Flight::route('GET /api/tass', function () {
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT code_insee, libcom, dep, circonscription,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0001), 5)::text AS geojson
            FROM tass_circonscriptions ORDER BY circonscription, code_insee";
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($db->query($sql))]);
});

Flight::route('GET /api/tsb/millesimes', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->query("SELECT DISTINCT millesime FROM tsb_circonscriptions ORDER BY millesime DESC");
    Flight::json($stmt->fetchAll(PDO::FETCH_COLUMN));
});

Flight::route('GET /api/tsb/tarifs', function () {
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $mil = (int)(Flight::request()->query['millesime'] ?? 0);
    if (!$mil) $mil = (int)$db->query("SELECT MAX(millesime) FROM tsb_tarifs")->fetchColumn();
    $stmt = $db->prepare("
        SELECT region, circonscription, type_local, tarif
        FROM tsb_tarifs WHERE millesime = :m
        ORDER BY region, circonscription, type_local
    ");
    $stmt->bindValue(':m', $mil, PDO::PARAM_INT);
    $stmt->execute();
    Flight::json(['millesime' => $mil, 'tarifs' => $stmt->fetchAll()]);
});

Flight::route('GET /api/tsb/tarifs/millesimes', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->query("SELECT DISTINCT millesime FROM tsb_tarifs ORDER BY millesime DESC");
    Flight::json($stmt->fetchAll(PDO::FETCH_COLUMN));
});

Flight::route('POST /api/tsb/tarifs/import', function () {
    $db  = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $body      = Flight::request()->data;
    $url       = trim($body['url']       ?? '');
    $millesime = (int)($body['millesime'] ?? 0);

    if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !str_contains($url, 'bofip.impots.gouv.fr')) {
        Flight::json(['error' => 'URL BOFIP invalide'], 400); return;
    }
    if ($millesime < 2010 || $millesime > 2050) {
        Flight::json(['error' => 'Millésime invalide'], 400); return;
    }

    // Parser via notre route BOFIP
    $parsed = @json_decode(file_get_contents('http://localhost/api/bofip/parse?url=' . urlencode($url)), true);
    if (!$parsed || empty($parsed['tables'])) {
        Flight::json(['error' => 'Aucun tableau trouvé sur cette page'], 422); return;
    }

    // Mapping en-tête → circ
    function circFromHeader(string $h): ?int {
        $h = strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$h));
        if (preg_match('/premi|1.?re?|1ère/', $h)) return 1;
        if (preg_match('/deuxi|2.?me/', $h))      return 2;
        if (preg_match('/troisi|3.?me/', $h))      return 3;
        if (preg_match('/quatri|4.?me/', $h))      return 4;
        return null;
    }
    function parseTarif(string $s): ?float {
        $s = preg_replace('/[^\d,.]/', '', $s);
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }
    function isPaca(string $caption): bool {
        $c = strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$caption));
        return str_contains($c,'bouches') || str_contains($c,'paca') || str_contains($c,'var') || str_contains($c,'provence');
    }
    function is2bis(string $caption): bool {
        $c = strtolower(iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$caption));
        return str_contains($c,'reduction') || str_contains($c,'reduit') || str_contains($c,'10 %') || str_contains($c,'derog');
    }

    $inserted = 0;
    $stmt = $db->prepare("
        INSERT INTO tsb_tarifs (millesime, region, circonscription, type_local, tarif, source_url)
        VALUES (:mil,:reg,:circ,:type,:tarif,:url)
        ON CONFLICT (millesime, region, COALESCE(circonscription,-1), type_local)
        DO UPDATE SET tarif=EXCLUDED.tarif, source_url=EXCLUDED.source_url
    ");

    foreach ($parsed['tables'] as $tbl) {
        $headers  = $tbl['headers'];
        $rows     = $tbl['rows'];
        $caption  = $tbl['caption'] ?? '';

        // Filtrer les lignes sans tarif
        $dataRows = array_filter($rows, fn($r) => count($r) > 1 && parseTarif($r[1] ?? '') !== null);
        if (empty($dataRows)) continue;

        if (isPaca($caption)) {
            $region = 'PACA'; $circ = null;
            foreach ($dataRows as $row) {
                $t = parseTarif($row[1] ?? '');
                if ($t === null) continue;
                $stmt->execute([':mil'=>$millesime,':reg'=>$region,':circ'=>$circ,':type'=>trim($row[0]),':tarif'=>$t,':url'=>$url]);
                $inserted++;
            }
        } elseif (is2bis($caption)) {
            $region = 'IDF_2BIS'; $circ = 2;
            foreach ($dataRows as $row) {
                $t = parseTarif($row[1] ?? '');
                if ($t === null) continue;
                $stmt->execute([':mil'=>$millesime,':reg'=>$region,':circ'=>$circ,':type'=>trim($row[0]),':tarif'=>$t,':url'=>$url]);
                $inserted++;
            }
        } else {
            // IDF principale : colonnes → circs
            $circCols = [];
            foreach ($headers as $ci => $h) {
                $c = circFromHeader($h);
                if ($c) $circCols[$ci] = $c;
            }
            // Fallback : chercher dans la première ligne de données si headers sans circ
            if (empty($circCols) && !empty($rows[0])) {
                foreach ($rows[0] as $ci => $cell) {
                    $c = circFromHeader((string)$cell);
                    if ($c) $circCols[$ci] = $c;
                }
                $dataRows = array_slice(array_values($dataRows), 1);
            }
            if (empty($circCols)) continue;
            foreach ($dataRows as $row) {
                $type = trim($row[0] ?? '');
                if (!$type) continue;
                foreach ($circCols as $ci => $c) {
                    $t = parseTarif($row[$ci] ?? '');
                    if ($t === null) continue;
                    $stmt->execute([':mil'=>$millesime,':reg'=>'IDF',':circ'=>$c,':type'=>$type,':tarif'=>$t,':url'=>$url]);
                    $inserted++;
                }
            }
        }
    }

    // Retourner le millésime mis à jour
    $check = $db->prepare("SELECT region, circonscription, COUNT(*) AS n FROM tsb_tarifs WHERE millesime=:m GROUP BY region, circonscription ORDER BY region, circonscription");
    $check->bindValue(':m', $millesime, PDO::PARAM_INT);
    $check->execute();

    Flight::json(['ok' => true, 'millesime' => $millesime, 'inserted' => $inserted, 'detail' => $check->fetchAll()]);
});

Flight::route('GET /api/tsb/stats', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $stmt = $db->query("
        SELECT millesime,
               COUNT(*) FILTER (WHERE region='IDF') AS idf_total,
               COUNT(*) FILTER (WHERE region='IDF' AND circonscription=1) AS idf_c1,
               COUNT(*) FILTER (WHERE region='IDF' AND circonscription=2) AS idf_c2,
               COUNT(*) FILTER (WHERE region='IDF' AND dcsucs AND dep='92') AS idf_2bis,
               COUNT(*) FILTER (WHERE region='IDF' AND circonscription=3) AS idf_c3,
               COUNT(*) FILTER (WHERE region='IDF' AND circonscription=4) AS idf_c4,
               COUNT(*) FILTER (WHERE region='IDF' AND dcsucs AND dep!='92') AS idf_dcsucs_derog,
               COUNT(*) FILTER (WHERE region='PACA') AS paca_total
        FROM tsb_circonscriptions
        GROUP BY millesime ORDER BY millesime DESC
    ");
    Flight::json($stmt->fetchAll());
});

Flight::route('POST /api/tsb/import', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $body     = Flight::request()->data;
    $url      = trim($body['url'] ?? '');
    $millesime = (int)($body['millesime'] ?? 0);

    if (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !str_contains($url, 'bofip.impots.gouv.fr')) {
        Flight::json(['error' => 'URL BOFIP invalide'], 400); return;
    }
    if ($millesime < 2010 || $millesime > 2050) {
        Flight::json(['error' => 'Millésime invalide'], 400); return;
    }

    // Parser le BOFIP via notre route existante
    $parseUrl = 'http://localhost/api/bofip/parse?url=' . urlencode($url);
    $parsed = @json_decode(file_get_contents($parseUrl), true);
    if (!$parsed || empty($parsed['listes'])) {
        Flight::json(['error' => 'Aucune liste de communes trouvée sur cette page'], 422); return;
    }

    $listes = $parsed['listes'];
    $nbDeps = count($listes);
    $nbComs = array_sum(array_map('count', $listes));

    // Normaliser les noms de communes → codes INSEE
    function normStr(string $s): string {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return strtolower(preg_replace('/[\W_]+/', ' ', $s));
    }

    $stmt = $db->query("SELECT insee, nom FROM communes_geom WHERE LEFT(insee,2) IN ('77','78','91','92','93','94','95')");
    $idxNom = [];
    foreach ($stmt as $row) $idxNom[normStr($row['nom'])] = $row['insee'];

    $dcsucs = [];
    foreach ($listes as $depNom => $noms) {
        foreach ($noms as $nom) {
            $n = normStr($nom);
            if (isset($idxNom[$n])) {
                $dcsucs[] = $idxNom[$n];
            } else {
                foreach ($idxNom as $k => $v) {
                    if (strpos($k, $n) !== false || strpos($n, $k) !== false) {
                        $dcsucs[] = $v; break;
                    }
                }
            }
        }
    }
    $dcsucs = array_unique($dcsucs);

    // Charger le template circ_naturelle (depuis n'importe quel millésime existant)
    $template = $db->query("
        SELECT DISTINCT ON (code_insee) code_insee, dep, libcom, circ_naturelle, region, geom
        FROM tsb_circonscriptions ORDER BY code_insee, millesime DESC
    ")->fetchAll();

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM tsb_circonscriptions WHERE millesime = :m")->execute([':m' => $millesime]);

        $ins = $db->prepare("
            INSERT INTO tsb_circonscriptions
                (code_insee, dep, libcom, circonscription, region, dcsucs, millesime, circ_naturelle, geom)
            VALUES (:ci, :dep, :lib, :circ, :reg, :dc, :mil, :cn, :geom)
        ");
        foreach ($template as $row) {
            $isDcsucs = in_array($row['code_insee'], $dcsucs);
            $circFinal = ($isDcsucs && $row['dep'] !== '92' && (int)$row['circ_naturelle'] === 3) ? 4 : (int)$row['circ_naturelle'];
            $ins->execute([
                ':ci' => $row['code_insee'], ':dep' => $row['dep'],
                ':lib' => $row['libcom'],    ':circ' => $circFinal,
                ':reg' => $row['region'],    ':dc' => $isDcsucs ? 't' : 'f',
                ':mil' => $millesime,        ':cn' => $row['circ_naturelle'],
                ':geom' => $row['geom'],
            ]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('[rsig] SQL error: ' . $e->getMessage());
        Flight::json(['error' => 'Erreur base de données'], 500); return;
    }

    Flight::json([
        'ok' => true,
        'millesime' => $millesime,
        'dcsucs_communes' => count($dcsucs),
        'deps_parsed' => $nbDeps,
        'communes_parsed' => $nbComs,
        'total_inserted' => count($template),
    ]);
});

Flight::route('GET /api/tsb', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $region = Flight::request()->query['region'] ?? '';
    if (!in_array($region, ['IDF', 'PACA'], true)) {
        Flight::json(['error' => 'region IDF ou PACA requise'], 400); return;
    }
    // Millésime : paramètre ou dernier disponible
    $millesimeParam = Flight::request()->query['millesime'] ?? '';
    if ($millesimeParam && preg_match('/^\d{4}$/', $millesimeParam)) {
        $millesime = (int)$millesimeParam;
    } else {
        $millesime = (int)$db->query("SELECT MAX(millesime) FROM tsb_circonscriptions")->fetchColumn();
    }
    $sql = "SELECT code_insee, libcom, dep, circonscription, region, dcsucs, millesime,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0001), 5)::text AS geojson
            FROM tsb_circonscriptions
            WHERE region = :region AND millesime = :millesime
            ORDER BY circonscription, code_insee";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':region', $region);
    $stmt->bindValue(':millesime', $millesime, PDO::PARAM_INT);
    $stmt->execute();
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});

// ── ZFU — Zones Franches Urbaines (IDF + PACA) ───────────
Flight::route('GET /api/zfu', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    $sql = "SELECT codquart, nom_quartier, communes,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0001), 5)::text AS geojson
            FROM zfu ORDER BY codquart";
    $stmt = $db->query($sql);
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

// ── BOFIP — parse tarifs / circonscriptions ───────────────
Flight::route('GET /api/bofip/parse', function () {
    $url = trim(Flight::request()->query['url'] ?? '');
    if (!$url) { Flight::json(['error' => 'url requis'], 400); return; }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !str_contains($url, 'bofip.impots.gouv.fr')) {
        Flight::json(['error' => 'URL invalide'], 400); return;
    }

    $ctx = stream_context_create(['http' => [
        'timeout'       => 15,
        'user_agent'    => 'Mozilla/5.0 (compatible; RSig/1.0)',
        'ignore_errors' => true,
    ]]);
    $html = @file_get_contents($url, false, $ctx);
    if (!$html) { Flight::json(['error' => 'Impossible de charger la page BOFIP'], 502); return; }

    if (mb_detect_encoding($html, 'UTF-8', true) === false) {
        $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $result = ['tables' => [], 'listes' => [], 'titre' => '', 'millesime' => null];

    // Titre
    $titres = $xpath->query('//h1 | //h2');
    if ($titres->length > 0) $result['titre'] = trim(preg_replace('/\s+/', ' ', $titres->item(0)->textContent));

    // ── Tables : tarifs ──────────────────────────────────
    foreach ($xpath->query('//table') as $table) {
        $headers = [];
        $rows    = [];
        // Caption de la table
        $captionNodes = $xpath->query('.//caption', $table);
        $caption = $captionNodes->length > 0
            ? trim(preg_replace('/\s+/', ' ', $captionNodes->item(0)->textContent))
            : '';
        foreach ($xpath->query('.//thead/tr/th | .//tr[1]/th', $table) as $th) {
            $headers[] = trim(preg_replace('/\s+/', ' ', $th->textContent));
        }
        foreach ($xpath->query('.//tbody/tr | .//tr[position()>1]', $table) as $tr) {
            $cells = [];
            foreach ($xpath->query('.//td | .//th', $tr) as $td) {
                $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
            }
            if (array_filter($cells)) $rows[] = $cells;
        }
        if ($headers && $rows) $result['tables'][] = ['caption' => $caption, 'headers' => $headers, 'rows' => $rows];
    }

    // ── Listes : communes DCSUCS ─────────────────────────
    // 3 formats selon la version BOFIP :
    //   A) <p>[.//strong] — "dans le département de X" en <strong> (2015-2022)
    //   B) <li>[.//strong] — même contenu dans <li> (2023+)
    //   C) <p> sans <strong> — "- dans le département de X : com1, com2" (2014)
    $communes = [];

    function extractDep(string $txt): ?string {
        if (preg_match('/d[ée]partement\s+(?:de\s+la\s+|du\s+|des\s+|de\s+l[\'´`\x{2019}]\s*|de\s+)(.+)/ui', $txt, $m))
            return trim(rtrim(trim($m[1]), ':'));
        return null;
    }
    function extractComs(string $fullTxt): array {
        $colonPos = strpos($fullTxt, ':');
        if ($colonPos === false) return [];
        $rest = trim(substr($fullTxt, $colonPos + 1));
        $rest = rtrim($rest, '; .\xc2\xa0');
        $rest = preg_replace('/\s+et\s+/ui', ',', $rest);
        $rest = preg_replace('/\xc2\xa0/', ' ', $rest); // nbsp
        return array_values(array_filter(array_map('trim', explode(',', $rest))));
    }

    // Formats A et B : nœuds <p> ou <li> contenant un <strong> avec "département"
    foreach ($xpath->query('//p[.//strong]|//li[.//strong]') as $node) {
        $strong = $xpath->query('.//strong', $node)->item(0);
        if (!$strong) continue;
        $depTxt = trim(preg_replace('/\s+/', ' ', $strong->textContent));
        if (!preg_match('/d[ée]partement/ui', $depTxt)) continue;
        $dep = extractDep($depTxt);
        if (!$dep) continue;
        $fullTxt = trim(preg_replace('/\s+/', ' ', $node->textContent));
        $coms = extractComs($fullTxt);
        if ($coms) $communes[$dep] = $coms;
    }

    // Format C : <p> sans <strong> contenant "dans le département de"
    if (!$communes) {
        foreach ($xpath->query('//p[not(.//strong)]') as $p) {
            $txt = trim(preg_replace('/\s+/', ' ', $p->textContent));
            if (!preg_match('/dans\s+le\s+d[ée]partement/ui', $txt)) continue;
            $dep = extractDep($txt);
            if (!$dep) continue;
            $coms = extractComs($txt);
            if ($coms) $communes[$dep] = $coms;
        }
    }

    if ($communes) $result['listes'] = $communes;

    // Millésime depuis l'URL
    if (preg_match('/(\d{8})$/', $url, $m)) $result['millesime'] = substr($m[1], 0, 4);

    Flight::json($result);
});

// ── Ortho — millésimes d'acquisition par campagne ────────────────────────────
// Retourne un GeoJSON de départements avec l'année d'acquisition dans la campagne
// ?campagne=2000-2005 | 2006-2010 | 2011-2015 | 2016-2020 | 2021-2023 | actuelle
Flight::route('GET /api/ortho/millesimes', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }

    $campagne = Flight::request()->query['campagne'] ?? 'actuelle';
    $allowed  = ['2000-2005','2006-2010','2011-2015','2016-2020','2021-2023','actuelle'];
    if (!in_array($campagne, $allowed, true)) {
        Flight::json(['error' => 'campagne invalide'], 400); return;
    }

    $sql = "SELECT d.code_insee AS code_dep, d.nom_officiel AS nom_dep,
                   o.annee_acq,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(d.geom, 0.01),4)::text AS geojson
            FROM departements_geom_4326 d
            JOIN ortho_millesimes_dept o ON o.code_dept = d.code_insee
            WHERE o.campagne = :campagne
            ORDER BY d.code_insee";

    $stmt = $db->prepare($sql);
    $stmt->execute([':campagne' => $campagne]);
    Flight::json(['type' => 'FeatureCollection', 'features' => rowsToGeoJson($stmt)]);
});
