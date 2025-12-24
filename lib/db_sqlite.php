<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function keira_sqlite_open(string $path) {
    if (!class_exists('SQLite3')) {
        throw new RuntimeException('PHP sqlite3 extension is not enabled');
    }
    if ($path === '' || !file_exists($path)) {
        throw new RuntimeException('SQLite db not found: ' . $path);
    }
    $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);
    return $db;
}

function keira_sqlite_query(string $path, string $sql): array {
    $db = keira_sqlite_open($path);

    $result = $db->query($sql);
    if ($result === false) {
        $err = $db->lastErrorMsg();
        $db->close();
        throw new RuntimeException('SQLite query failed: ' . $err);
    }

    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    $result->finalize();
    $db->close();

    return $rows;
}
