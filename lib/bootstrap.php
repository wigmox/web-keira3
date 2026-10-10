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
    // 未配置时按 30 分钟处理：漏配 config 不应等于会话永不过期。
    $idleTimeout = (int)($sessionCfg['idle_timeout_seconds'] ?? 0);
    if ($idleTimeout <= 0) {
        $idleTimeout = 1800;
    }

    $now = time();
    $last = (int)($_SESSION['keira_auth_last'] ?? ($_SESSION['keira_auth_at'] ?? 0));
    if ($last > 0 && ($now - $last) > $idleTimeout) {
        // Expired idle session
        $_SESSION = [];
        return false;
    }
    $_SESSION['keira_auth_last'] = $now;

    return true;
}

function keira_login_rate_limit(): array {
    $rl = keira_config()['app']['rate_limit']['login'] ?? [];
    $maxAttempts = (int)($rl['max_attempts'] ?? 0);
    $windowSeconds = (int)($rl['window_seconds'] ?? 0);

    if ($maxAttempts <= 0) {
        $maxAttempts = 5;
    }
    if ($windowSeconds <= 0) {
        $windowSeconds = 900;
    }

    return [$maxAttempts, $windowSeconds];
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

// 可选的客户端来源白名单：支持单个 IP 与 10.0.0.0/8 形式的网段；未配置即不限制。
function keira_ip_in_cidr(string $ip, string $cidr): bool {
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2 || !is_numeric($parts[1])) {
        return false;
    }

    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($parts[0]);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $bits = (int)$parts[1];
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }

    $bytes = intdiv($bits, 8);
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }

    $rem = $bits % 8;
    if ($rem === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
}

function keira_is_ip_allowed(): bool {
    $allowed = keira_config()['app']['allowed_ips'] ?? [];
    if (!is_array($allowed) || $allowed === []) {
        return true;
    }

    $ip = keira_client_ip();
    foreach ($allowed as $entry) {
        $entry = trim((string)$entry);
        if ($entry === '') {
            continue;
        }
        if ($entry === $ip || (strpos($entry, '/') !== false && keira_ip_in_cidr($ip, $entry))) {
            return true;
        }
    }

    return false;
}

function keira_enforce_ip_allowlist(): void {
    if (keira_is_ip_allowed()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

function keira_rate_limit_dir(): string {
    $cfg = keira_config();
    $dir = (string)($cfg['app']['rate_limit']['dir'] ?? '');
    if ($dir === '') {
        // 默认落在项目内 storage/ratelimit：sys_get_temp_dir() 在部分 Windows 部署下不可写，
        // 而限流器打不开文件时是放行的，会让限流静默失效。
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit';
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
