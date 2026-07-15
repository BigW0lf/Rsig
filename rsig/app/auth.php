<?php

function authStart(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function isAuthenticated(): bool {
    if (!AUTH_ENABLED) return true;
    authStart();
    return isset($_SESSION['user_tid']) && $_SESSION['user_tid'] === DYN_TENANT_ID;
}

function isAdmin(): bool {
    // En local (AUTH_ENABLED=false), pas d'auth → admin par défaut
    if (!AUTH_ENABLED) return true;
    if (!isAuthenticated()) return false;
    $adminEmail = ADMIN_EMAIL;
    if (!$adminEmail) return false;
    return strtolower($_SESSION['user_email'] ?? '') === strtolower($adminEmail);
}

function requireAdmin(): void {
    requireAuth();
    if (!isAdmin()) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>403 Accès refusé</title>'
           . '<link rel="stylesheet" href="/assets/style.css"></head><body>'
           . '<div style="padding:60px;text-align:center">'
           . '<h2 style="color:var(--accent)">Accès refusé</h2>'
           . '<p style="color:var(--text2)">Cette page est réservée à l\'administrateur.</p>'
           . '<a href="/" class="btn" style="display:inline-block;margin-top:16px">← Retour à la carte</a>'
           . '</div></body></html>';
        exit;
    }
}

function requireAuth(): void {
    if (!AUTH_ENABLED) return;
    if (isAuthenticated()) return;
    authStart();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_return'] = $_SERVER['REQUEST_URI'] ?? '/';
    $params = http_build_query([
        'client_id'     => DYN_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri'  => authCallbackUrl(),
        'response_mode' => 'query',
        'scope'         => 'openid profile email',
        'state'         => $state,
    ]);
    header('Location: https://login.microsoftonline.com/' . DYN_TENANT_ID . '/oauth2/v2.0/authorize?' . $params);
    exit;
}

function authCallbackUrl(): string {
    return 'https://rcarto.rtaxes-geometre-expert.fr/auth/callback';
}

function handleAuthCallback(): void {
    authStart();
    $code  = $_GET['code']  ?? '';
    $state = $_GET['state'] ?? '';

    if (!$code || !$state || $state !== ($_SESSION['oauth_state'] ?? '')) {
        http_response_code(400); echo 'Erreur OAuth : état invalide.'; exit;
    }
    unset($_SESSION['oauth_state']);

    $response = file_get_contents(
        'https://login.microsoftonline.com/' . DYN_TENANT_ID . '/oauth2/v2.0/token',
        false,
        stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'client_id'     => DYN_CLIENT_ID,
                'client_secret' => DYN_CLIENT_SECRET,
                'code'          => $code,
                'redirect_uri'  => authCallbackUrl(),
                'grant_type'    => 'authorization_code',
            ]),
            'ignore_errors' => true,
        ]])
    );

    $token = json_decode($response, true);
    if (empty($token['id_token'])) {
        http_response_code(401);
        echo 'Échec de l\'authentification Microsoft.';
        exit;
    }

    // Décoder le payload JWT (pas de vérification de signature — confiance au TLS + tenant check)
    $parts   = explode('.', $token['id_token']);
    $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);

    if (($payload['tid'] ?? '') !== DYN_TENANT_ID) {
        http_response_code(403); echo 'Accès refusé : compte non autorisé.'; exit;
    }

    $_SESSION['user_tid']   = $payload['tid'];
    $_SESSION['user_name']  = $payload['name']              ?? $payload['preferred_username'] ?? 'Inconnu';
    $_SESSION['user_email'] = $payload['preferred_username'] ?? $payload['email']             ?? '';

    $return = $_SESSION['oauth_return'] ?? '/';
    unset($_SESSION['oauth_return']);
    header('Location: ' . $return);
    exit;
}

function handleLogout(): void {
    authStart();
    session_destroy();
    $params = http_build_query([
        'client_id'                => DYN_CLIENT_ID,
        'post_logout_redirect_uri' => 'https://rcarto.rtaxes-geometre-expert.fr/',
    ]);
    header('Location: https://login.microsoftonline.com/' . DYN_TENANT_ID . '/oauth2/v2.0/logout?' . $params);
    exit;
}
