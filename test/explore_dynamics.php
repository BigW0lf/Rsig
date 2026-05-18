<?php
// Script temporaire - explore la structure de apo_dossiers sur Dynamics
// Appeler via : php explore_dynamics.php
require __DIR__ . '/config.php';

function getToken() {
    $url = "https://login.microsoftonline.com/" . DYN_TENANT_ID . "/oauth2/v2.0/token";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            "grant_type"    => "client_credentials",
            "client_id"     => DYN_CLIENT_ID,
            "client_secret" => DYN_CLIENT_SECRET,
            "scope"         => DYN_SCOPE,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true)['access_token'] ?? null;
}

function callApi($url, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Accept: application/json",
            "OData-Version: 4.0",
            "Prefer: odata.include-annotations=*"
        ]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

$token = getToken();
if (!$token) {
    die("Erreur : impossible d'obtenir un token\n");
}
echo "Token OK\n\n";

$base = "https://rtaxes.api.crm4.dynamics.com/api/data/v9.2";
$data = callApi("$base/apo_dossiers?\$top=1", $token);

if (isset($data['error'])) {
    echo "Erreur API : " . json_encode($data['error'], JSON_PRETTY_PRINT) . "\n";
    exit;
}

if (empty($data['value'])) {
    echo "Table apo_dossiers vide ou inaccessible\n";
    exit;
}

$record = $data['value'][0];
echo "=== Champs disponibles dans apo_dossiers ===\n";
foreach ($record as $key => $val) {
    $type = gettype($val);
    $preview = is_string($val) ? substr($val, 0, 60) : json_encode($val);
    echo sprintf("  %-50s [%s] = %s\n", $key, $type, $preview);
}

echo "\n=== Total champs : " . count($record) . " ===\n";
