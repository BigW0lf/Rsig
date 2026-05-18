<?php
declare(strict_types=1);
require_once 'flight/Flight.php';

// ── DB connection ────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('pgsql:host=localhost;dbname=mabase', 'postgres', 'postgres', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
    return $pdo;
}

// ── Helper : réponse GeoJSON ─────────────────────────────────────────────────
function geojson(array $features): void {
    Flight::response()->header('Content-Type', 'application/json');
    Flight::response()->header('Access-Control-Allow-Origin', '*');
    echo json_encode(['type' => 'FeatureCollection', 'features' => $features]);
    Flight::stop();
}

// ── Helper : réponse JSON simple ─────────────────────────────────────────────
function json_out(mixed $data): void {
    Flight::response()->header('Content-Type', 'application/json');
    Flight::response()->header('Access-Control-Allow-Origin', '*');
    echo json_encode($data);
    Flight::stop();
}

// ── Helper : parse bbox query param ─────────────────────────────────────────
function bbox(): ?array {
    $b = Flight::request()->query['bbox'] ?? null;
    if (!$b) return null;
    $p = array_map('floatval', explode(',', $b));
    return count($p) === 4 ? $p : null;
}

// ════════════════════════════════════════════════════════════════════════════
// PAGE PRINCIPALE
// ════════════════════════════════════════════════════════════════════════════
Flight::route('GET /', function () {
    require 'views/carte.php';
});

// ════════════════════════════════════════════════════════════════════════════
// API — taux_clean  (communes, géom polygone)
// ════════════════════════════════════════════════════════════════════════════
Flight::route('GET /api/taux', function () {
    $b = bbox();
    $champ = Flight::request()->query['champ'] ?? 'taux_fb_commune_vote';
    // whitelist des colonnes autorisées
    $allowed = ['taux_fnb_commune','taux_fnb_syndicats_net','taux_fnb_gfp_vote',
                'taux_tafnb_commune_net','taux_tafnb_gfp_net','taux_tse_net',
                'taux_tse_autres_net','taux_tse_gemapi_net','taux_fb_commune_vote',
                'taux_fb_syndicats_net','taux_fb_gfp_vote','taux_fb_tse_net',
                'taux_fb_tse_autres_net','taux_fb_gemapi_net','taux_fb_tasa_net',
                'taux_teom_plein'];
    if (!in_array($champ, $allowed, true)) $champ = 'taux_fb_commune_vote';

    $where = $b
        ? "WHERE ST_Intersects(geom, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),2154))"
        : "";

    $sql = "SELECT ogc_fid, dep, com, libcom, millesime,
                   taux_fnb_commune, taux_fb_commune_vote, taux_tse_net,
                   taux_teom_plein, $champ AS valeur_affichee,
                   ST_AsGeoJSON(ST_Transform(geom,4326),6) AS geojson
            FROM taux_clean $where LIMIT 5000";

    $stmt = db()->prepare($sql);
    if ($b) { $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
              $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]); }
    $stmt->execute();

    $features = [];
    foreach ($stmt as $row) {
        $g = json_decode($row['geojson'], true);
        unset($row['geojson']);
        $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>$row];
    }
    geojson($features);
});

// ════════════════════════════════════════════════════════════════════════════
// API — coeff_loc_final  (parcelles, bbox obligatoire)
// ════════════════════════════════════════════════════════════════════════════
Flight::route('GET /api/coeff', function () {
    $b = bbox();
    if (!$b) { json_out(['error' => 'bbox requis']); return; }

    $sql = "SELECT ogc_fid, idu, codecommune, section, parcelle,
                   coeff_2017, coeff_2018, coeff_2019, coeff_2020,
                   coeff_2024, coeff_2026, evolution,
                   ST_AsGeoJSON(ST_Transform(geom,4326),6) AS geojson
            FROM coeff_loc_final
            WHERE ST_Intersects(geom, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),2154))
            LIMIT 2000";

    $stmt = db()->prepare($sql);
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->execute();

    $features = [];
    foreach ($stmt as $row) {
        $g = json_decode($row['geojson'], true);
        unset($row['geojson']);
        $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>$row];
    }
    geojson($features);
});

// ════════════════════════════════════════════════════════════════════════════
// API — dossier_acc_geo  (points, pas de bbox nécessaire, 4k seulement)
// ════════════════════════════════════════════════════════════════════════════
Flight::route('GET /api/dossiers', function () {
    $b = bbox();
    $where = $b
        ? "WHERE ST_Intersects(geom::geometry, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),2154))"
        : "";

    $sql = "SELECT ogc_fid, rtx_code, name, apo_montanttaxefonciere,
                   adresse_complete, dossier, lot, prefix, section, insee,
                   ST_AsGeoJSON(ST_Transform(geom::geometry,4326),6) AS geojson
            FROM dossier_acc_geo
            WHERE geom IS NOT NULL $where";

    $stmt = db()->prepare($sql);
    if ($b) { $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
              $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]); }
    $stmt->execute();

    $features = [];
    foreach ($stmt as $row) {
        $g = json_decode($row['geojson'], true);
        unset($row['geojson']);
        $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>$row];
    }
    geojson($features);
});

// ════════════════════════════════════════════════════════════════════════════
// API — sections_tarifs  (sections, bbox + categorie obligatoires)
// ════════════════════════════════════════════════════════════════════════════
Flight::route('GET /api/tarifs', function () {
    $b    = bbox();
    $cat  = Flight::request()->query['categorie'] ?? '';
    if (!$b || !$cat) { json_out(['error' => 'bbox et categorie requis']); return; }

    // whitelist categorie : lettres maj + chiffre
    if (!preg_match('/^[A-Z]{3}[0-9]$/', $cat)) {
        json_out(['error' => 'categorie invalide']); return;
    }

    $annee = Flight::request()->query['annee'] ?? '2025';
    $annees_ok = ['2017','2019','2020','2021','2022','2023','2024','2025','2026'];
    if (!in_array($annee, $annees_ok, true)) $annee = '2025';
    $col = "val_$annee";

    $sql = "SELECT s.ogc_fid, s.code_dep, s.code_insee, s.nom_com, s.section, s.secteur,
                   t.categorie, t.$col AS valeur,
                   t.val_2017, t.val_2019, t.val_2020, t.val_2021,
                   t.val_2022, t.val_2023, t.val_2024, t.val_2025, t.val_2026,
                   ST_AsGeoJSON(ST_Transform(s.geom,4326),6) AS geojson
            FROM sections_2025 s
            JOIN tarifs_pivot t
                ON t.dep = CASE WHEN s.code_dep='97' THEN left(s.code_insee,3) ELSE s.code_dep END
               AND t.num_secteur = s.secteur
               AND t.categorie = :cat
            WHERE ST_Intersects(s.geom, ST_Transform(ST_MakeEnvelope(:x1,:y1,:x2,:y2,4326),2154))
            LIMIT 3000";

    $stmt = db()->prepare($sql);
    $stmt->bindValue(':cat', $cat);
    $stmt->bindValue(':x1',$b[0]); $stmt->bindValue(':y1',$b[1]);
    $stmt->bindValue(':x2',$b[2]); $stmt->bindValue(':y2',$b[3]);
    $stmt->execute();

    $features = [];
    foreach ($stmt as $row) {
        $g = json_decode($row['geojson'], true);
        unset($row['geojson']);
        $features[] = ['type'=>'Feature','geometry'=>$g,'properties'=>$row];
    }
    geojson($features);
});

// ════════════════════════════════════════════════════════════════════════════
// API — liste des catégories tarifs
// ════════════════════════════════════════════════════════════════════════════
Flight::route('GET /api/tarifs/categories', function () {
    $stmt = db()->query("SELECT DISTINCT categorie FROM tarifs_pivot ORDER BY categorie");
    json_out($stmt->fetchAll(PDO::FETCH_COLUMN));
});

Flight::start();
