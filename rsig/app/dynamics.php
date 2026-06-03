<?php

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
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return '';
    $json = json_decode($raw, true);
    return $json['access_token'] ?? '';
}

function callDynamics(string $value, string $field, string $table): array {
    $token = getAccessToken();
    if (!$token) return ['error' => 'Token indisponible'];
    // Échapper la valeur pour OData : doubler les apostrophes (standard OData)
    $safeValue = str_replace("'", "''", $value);
    $url   = "https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/$table"
           . '?$filter=' . urlencode("$field eq '$safeValue'")
           . '&$top=5';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Accept: application/json",
            "OData-Version: 4.0",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code !== 200) return ['error' => 'Dynamics ' . $code];
    return json_decode($raw, true) ?? [];
}
