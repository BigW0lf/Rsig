<?php

define('DB_FLAG_PATH', __DIR__ . '/../../db_offline.flag');

function isDbOffline(): bool {
    return file_exists(DB_FLAG_PATH);
}

function getDb(): ?PDO {
    if (isDbOffline()) return null;
    try {
        return new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException) {
        return null;
    }
}

function parseBbox(): ?array {
    $b = Flight::request()->query['bbox'] ?? null;
    if (!$b) return null;
    $p = array_map('floatval', explode(',', $b));
    return count($p) === 4 ? $p : null;
}

function bboxWhere(string $geomCol = 'geom', string $srid = '2154'): string {
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

function validateChamp(string $champ, array $allowed, string $default): string {
    return in_array($champ, $allowed, true) ? $champ : $default;
}

function validateAnnee(string $annee): string {
    $valid = ['2017','2019','2020','2021','2022','2023','2024','2025','2026'];
    return in_array($annee, $valid, true) ? $annee : '2025';
}

function validateMillesime(string $m): string {
    return (preg_match('/^\d{4}$/', $m) && (int)$m >= 2010 && (int)$m <= 2035) ? $m : '2025';
}

function isNotModified(string $etag): bool {
    $inm = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    // Nginx ajoute "-gzip" au suffix ETag lors de la compression — normaliser
    $inm = preg_replace('/-gzip"$/', '"', $inm);
    return $inm === $etag;
}

// ── Cache fichier JSON (TTL en secondes) ─────────────────────────────────────
define('CACHE_DIR', __DIR__ . '/../cache/');

function cacheGet(string $key): mixed {
    $f = CACHE_DIR . md5($key) . '.json';
    if (!file_exists($f)) return null;
    $data = json_decode(file_get_contents($f), true);
    if (!$data || $data['expires'] < time()) { @unlink($f); return null; }
    return $data['payload'];
}

function cacheSet(string $key, mixed $payload, int $ttl = 300): void {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    file_put_contents(
        CACHE_DIR . md5($key) . '.json',
        json_encode(['expires' => time() + $ttl, 'payload' => $payload])
    );
}

const TAUX_CHAMPS = [
    // TFPB
    'taux_fb_commune_vote','taux_fb_syndicats_net','taux_fb_gfp_vote',
    'taux_tse_net','taux_tafnb_commune_net','taux_teom_plein','taux_tse_gemapi_net',
    // TFPNB
    'taux_fnb_commune','taux_fnb_syndicats_net','taux_fnb_gfp_vote','taux_tafnb_gfp_net',
];

const COEFF_CHAMPS = ['coeff_2017','coeff_2018','coeff_2019','coeff_2020','coeff_2024','coeff_2026'];
