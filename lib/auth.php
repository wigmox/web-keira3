<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function keira_auth_login(string $username, string $password): bool {
    $cfg = keira_config();
    $auth = $cfg['app']['auth'] ?? [];

    $expectedUser = (string)($auth['username'] ?? '');
    $expectedHash = (string)($auth['password_hash'] ?? '');

    if ($expectedUser === '' || $expectedHash === '') {
        return false;
    }

    if (!hash_equals($expectedUser, $username)) {
        return false;
    }

    return password_verify($password, $expectedHash);
}

function keira_set_logged_in(): void {
    keira_start_session();
    session_regenerate_id(true);
    $_SESSION['keira_auth'] = true;
    $_SESSION['keira_auth_at'] = time();
    $_SESSION['keira_auth_last'] = time();
}

function keira_logout(): void {
    keira_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
