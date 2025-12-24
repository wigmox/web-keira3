<?php

declare(strict_types=1);

function keira_config(): array {
    static $config = null;
    if ($config === null) {
        $configPath = __DIR__ . '/../config/config.php';
        if (!is_file($configPath)) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo "Keira 配置缺失：config/config.php\n";
            echo "请将 config/config.example.php 复制为 config/config.php 并按需修改。\n";
            exit;
        }
        $config = require $configPath;
    }
    return $config;
}

function keira_base_path(): string {
    $base = (string)(keira_config()['app']['base_path'] ?? '/keira');
    if ($base === '') {
        return '';
    }
    return rtrim($base, '/');
}

function keira_is_https(): bool {
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https === 'on' || $https === '1' || $https === 'true') {
        return true;
    }

    $scheme = strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? ''));
    if ($scheme === 'https') {
        return true;
    }

    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProto === 'https') {
        return true;
    }

    $frontEndHttps = strtolower((string)($_SERVER['HTTP_FRONT_END_HTTPS'] ?? ''));
    if ($frontEndHttps === 'on') {
        return true;
    }

    return false;
}

function keira_start_session(): void {
    $cfg = keira_config();
    $sessionName = (string)($cfg['app']['session']['name'] ?? 'KEIRA_SID');

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name($sessionName);

    $secureConfigured = (bool)($cfg['app']['session']['cookie_secure'] ?? false);
    $secure = $secureConfigured && keira_is_https();
    $sameSite = (string)($cfg['app']['session']['cookie_samesite'] ?? 'Strict');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => keira_base_path() . '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);

    session_start();
}

function keira_is_logged_in(): bool {
    keira_start_session();

    if (!isset($_SESSION['keira_auth']) || $_SESSION['keira_auth'] !== true) {
        return false;
    }

    $cfg = keira_config();
    $sessionCfg = $cfg['app']['session'] ?? [];
    $idleTimeout = (int)($sessionCfg['idle_timeout_seconds'] ?? 0);

    if ($idleTimeout > 0) {
        $now = time();
        $last = (int)($_SESSION['keira_auth_last'] ?? ($_SESSION['keira_auth_at'] ?? 0));
        if ($last > 0 && ($now - $last) > $idleTimeout) {
            // Expired idle session
            $_SESSION = [];
            return false;
        }
        $_SESSION['keira_auth_last'] = $now;
    }

    return true;
}

function keira_require_login_json(): void {
    if (!keira_is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function keira_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function keira_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function keira_client_ip(): string {
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $cfg = keira_config();
    $trusted = $cfg['app']['trusted_proxies'] ?? [];
    $isTrustedProxy = is_array($trusted) && $remoteAddr !== '' && in_array($remoteAddr, $trusted, true);

    if ($isTrustedProxy) {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            // Take the left-most (original client) IP.
            $parts = array_map('trim', explode(',', $xff));
            $candidate = (string)($parts[0] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    return $remoteAddr !== '' ? $remoteAddr : 'unknown';
}

function keira_rate_limit_dir(): string {
    $cfg = keira_config();
    $dir = (string)($cfg['app']['rate_limit']['dir'] ?? '');
    if ($dir === '') {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'keira-ratelimit';
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

/**
 * Simple file-based rate limiter.
 *
 * Returns true if the request is allowed and records the attempt.
 * Returns false if the limit is exceeded.
 */
function keira_rate_limit_allow(string $bucket, string $ip, int $maxAttempts, int $windowSeconds): bool {
    if ($maxAttempts <= 0 || $windowSeconds <= 0) {
        return true;
    }

    $key = $bucket . '|' . $ip;
    $path = keira_rate_limit_dir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
    $now = time();

    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        // Fail-open to avoid locking users out due to filesystem issues.
        return true;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return true;
        }

        $raw = stream_get_contents($fp);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $count = (int)($state['count'] ?? 0);
        $resetAt = (int)($state['reset_at'] ?? 0);

        if ($resetAt <= 0 || $now >= $resetAt) {
            $count = 0;
            $resetAt = $now + $windowSeconds;
        }

        if ($count >= $maxAttempts) {
            return false;
        }

        $count++;
        $newState = ['count' => $count, 'reset_at' => $resetAt];

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($newState, JSON_UNESCAPED_UNICODE));
        fflush($fp);

        return true;
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}
