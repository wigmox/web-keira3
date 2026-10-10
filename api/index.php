<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db_mysql.php';
require_once __DIR__ . '/../lib/db_sqlite.php';

keira_enforce_ip_allowlist();

$base = keira_base_path();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$path = is_string($path) ? $path : '';

$apiPrefix = $base . '/api';
if (str_starts_with($path, $apiPrefix)) {
    $path = substr($path, strlen($apiPrefix));
}
$path = '/' . ltrim($path, '/');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// -------- Auth --------
if ($path === '/auth/me' && $method === 'GET') {
    keira_json(['authenticated' => keira_is_logged_in()]);
}

if ($path === '/auth/login' && $method === 'POST') {
    [$maxAttempts, $windowSeconds] = keira_login_rate_limit();
    $ip = keira_client_ip();
    if (!keira_rate_limit_allow('auth_login', $ip, $maxAttempts, $windowSeconds)) {
        keira_json(['ok' => false, 'error' => 'rate_limited'], 429);
    }

    $body = keira_read_json_body();
    $username = (string)($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');

    if (keira_auth_login($username, $password)) {
        keira_set_logged_in();
        keira_json(['ok' => true]);
    }

    keira_json(['ok' => false, 'error' => 'invalid_credentials'], 403);
}

if ($path === '/auth/logout' && $method === 'POST') {
    keira_logout();
    keira_json(['ok' => true]);
}
// -------- Protected routes --------
keira_require_login_json();

// -------- MySQL --------
if ($path === '/mysql/connect' && $method === 'POST') {
    try {
        $body = keira_read_json_body();

        // If frontend provides connection details, prefer them and store in session.
        // Otherwise fall back to server config for backward compatibility.
        $hasFrontendConfig = is_array($body)
            && (array_key_exists('host', $body)
                || array_key_exists('port', $body)
                || array_key_exists('user', $body)
                || array_key_exists('password', $body)
                || array_key_exists('database', $body)
                || array_key_exists('ssl', $body));

        if ($hasFrontendConfig) {
            $opts = keira_mysql_validate_connect_options($body);
            $mysqli = keira_mysql_connect_with_options($opts);
            $mysqli->set_charset('utf8mb4');
            $mysqli->close();

            keira_mysql_set_session_connect_options($opts);

            keira_json([
                'ok' => true,
                'config' => [
                    'host' => (string)$opts['host'],
                    'port' => (int)$opts['port'],
                    'user' => (string)$opts['user'],
                    'database' => (string)$opts['database'],
                    'ssl' => (bool)$opts['ssl'],
                ],
            ]);
        }

        // Legacy: use server-side config
        $mysqli = keira_mysql_connect();
        $cfg = keira_config();
        $db = $cfg['mysql'] ?? [];
        $mysqli->close();

        keira_json([
            'ok' => true,
            'config' => [
                'host' => (string)($db['host'] ?? ''),
                'port' => (int)($db['port'] ?? 3306),
                'user' => (string)($db['user'] ?? ''),
                'database' => (string)($db['database'] ?? ''),
                'ssl' => (bool)($db['ssl'] ?? false),
            ],
        ]);
    } catch (Throwable $e) {
        keira_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($path === '/mysql/query' && $method === 'POST') {
    $body = keira_read_json_body();
    $queryString = (string)($body['queryString'] ?? '');

    if ($queryString === '') {
        keira_json(['ok' => false, 'error' => 'empty_query'], 400);
    }

    try {
        $mysqli = keira_mysql_connect();
        $out = keira_mysql_run_query($mysqli, $queryString);
        $mysqli->close();

        keira_json($out);
    } catch (Throwable $e) {
        keira_json(['ok' => false, 'error' => $e->getMessage(), 'query' => $queryString], 500);
    }
}

// -------- SQLite --------
if ($path === '/sqlite/query' && $method === 'POST') {
    $body = keira_read_json_body();
    $queryString = (string)($body['queryString'] ?? '');
    $dbName = (string)($body['db'] ?? 'main');

    if ($queryString === '') {
        keira_json(['ok' => false, 'error' => 'empty_query'], 400);
    }

    try {
        $cfg = keira_config();
        $sqliteCfg = $cfg['sqlite'] ?? [];
        $path = $dbName === 'item3d'
            ? (string)($sqliteCfg['sqlite_item3d_path'] ?? '')
            : (string)($sqliteCfg['sqlite_path'] ?? '');

        $rows = keira_sqlite_query($path, $queryString);
        keira_json($rows);
    } catch (Throwable $e) {
        keira_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

keira_json(['error' => 'not_found', 'path' => $path], 404);
