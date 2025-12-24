<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function keira_mysql_validate_connect_options(array $in): array {
    $host = (string)($in['host'] ?? '');
    $user = (string)($in['user'] ?? '');
    $password = (string)($in['password'] ?? '');
    $database = (string)($in['database'] ?? '');
    $portRaw = $in['port'] ?? 3306;
    $ssl = (bool)($in['ssl'] ?? false);

    // Basic sanity checks
    $host = trim($host);
    $user = trim($user);
    $database = trim($database);

    $port = is_int($portRaw) ? $portRaw : (int)trim((string)$portRaw);
    if ($port <= 0 || $port > 65535) {
        throw new RuntimeException('Invalid MySQL port');
    }

    if ($host === '' || strlen($host) > 255) {
        throw new RuntimeException('Invalid MySQL host');
    }
    // Disallow scheme prefixes (prevent accidental URLs)
    if (str_contains($host, '://')) {
        throw new RuntimeException('Invalid MySQL host');
    }
    if ($user === '' || strlen($user) > 128) {
        throw new RuntimeException('Invalid MySQL username');
    }
    if ($database === '' || strlen($database) > 128) {
        throw new RuntimeException('Invalid MySQL database');
    }
    if (strlen($password) > 512) {
        throw new RuntimeException('Invalid MySQL password');
    }

    return [
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'password' => $password,
        'database' => $database,
        'ssl' => $ssl,
    ];
}

function keira_mysql_set_session_connect_options(array $opts): void {
    keira_start_session();
    // Store password server-side in session so subsequent /mysql/query can reuse it.
    $_SESSION['keira_mysql'] = $opts;
}

function keira_mysql_get_session_connect_options(): ?array {
    keira_start_session();
    $opts = $_SESSION['keira_mysql'] ?? null;
    return is_array($opts) ? $opts : null;
}

function keira_mysql_connect_with_options(array $opts): mysqli {
    $host = (string)($opts['host'] ?? '127.0.0.1');
    $port = (int)($opts['port'] ?? 3306);
    $user = (string)($opts['user'] ?? 'root');
    $pass = (string)($opts['password'] ?? '');
    $database = (string)($opts['database'] ?? '');
    $ssl = (bool)($opts['ssl'] ?? false);

    $mysqli = mysqli_init();
    if ($mysqli === false) {
        throw new RuntimeException('mysqli_init failed');
    }

    $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

    $clientFlags = 0;
    if ($ssl) {
        // Use system/Windows cert store defaults; no client cert.
        $mysqli->ssl_set(null, null, null, null, null);
        $clientFlags |= MYSQLI_CLIENT_SSL;
    }

    if (!$mysqli->real_connect($host, $user, $pass, $database, $port, null, $clientFlags)) {
        throw new RuntimeException('MySQL connect failed: ' . mysqli_connect_error());
    }

    return $mysqli;
}

function keira_mysql_connect(): mysqli {
    // Prefer session-stored options (provided by frontend), fall back to server config.
    $sessionOpts = keira_mysql_get_session_connect_options();
    if (is_array($sessionOpts)) {
        $mysqli = keira_mysql_connect_with_options($sessionOpts);
        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    $cfg = keira_config();
    $db = $cfg['mysql'] ?? [];

    $mysqli = keira_mysql_connect_with_options([
        'host' => (string)($db['host'] ?? '127.0.0.1'),
        'port' => (int)($db['port'] ?? 3306),
        'user' => (string)($db['user'] ?? 'root'),
        'password' => (string)($db['password'] ?? ''),
        'database' => (string)($db['database'] ?? ''),
        'ssl' => (bool)($db['ssl'] ?? false),
    ]);

    $charset = (string)($db['charset'] ?? 'utf8mb4');
    $mysqli->set_charset($charset);

    return $mysqli;
}

function keira_mysql_run_query(mysqli $mysqli, string $sql): array {

    $rows = null;
    $fields = null;

    if ($mysqli->multi_query($sql) === false) {
        throw new RuntimeException('Query failed: ' . $mysqli->error);
    }

    $isFirst = true;
    do {
        $result = $mysqli->store_result();
        if ($isFirst) {
            if ($result instanceof mysqli_result) {
                $fields = [];
                foreach ($result->fetch_fields() as $f) {
                    $fields[] = [
                        'name' => $f->name,
                        'table' => $f->table,
                        'type' => $f->type,
                        'flags' => $f->flags,
                        'length' => $f->length,
                        'decimals' => $f->decimals,
                    ];
                }

                $rows = [];
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
                $result->free();
            } else {
                // 非 SELECT
                $rows = null;
                $fields = null;
            }
        } else {
            if ($result instanceof mysqli_result) {
                $result->free();
            }
        }

        $isFirst = false;
    } while ($mysqli->more_results() && $mysqli->next_result());

    if ($mysqli->errno) {
        throw new RuntimeException('Query error: ' . $mysqli->error);
    }

    return [
        'result' => $rows,
        'fields' => $fields,
    ];
}
