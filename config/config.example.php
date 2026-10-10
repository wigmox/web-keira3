<?php

declare(strict_types=1);

// Copy to `config.php` and adjust values.

return [
    'app' => [
        // Keira 壳访问前缀：部署在站点根目录 keira 子目录时保持 /keira
        'base_path' => '/keira3',

        // 单用户登录账号
        'auth' => [
            'username' => 'admin',
            // 用 `php -r "echo password_hash('yourPassword', PASSWORD_DEFAULT), PHP_EOL;"` 生成
            'password_hash' => '',
        ],

        // 安全相关
        'session' => [
            'name' => 'KEIRA_SID',
            'cookie_secure' => true, // HTTPS 下为 true
            'cookie_samesite' => 'Strict',
            // 空闲超时（秒）；不配置按 1800 处理
            'idle_timeout_seconds' => 1800,
        ],

        // 登录限流；不配置按 window_seconds=900 / max_attempts=5 处理
        'rate_limit' => [
            'login' => [
                'window_seconds' => 900,
                'max_attempts' => 5,
            ],
        ],

        // 客户端来源白名单：留空表示不限制；支持单个 IP 与 10.0.0.0/8 形式的网段
        'allowed_ips' => [
            // '127.0.0.1',
            // '10.0.0.0/8',
        ],

        // 反向代理地址；只有来自这些地址的 X-Forwarded-For 才会被采信
        'trusted_proxies' => [
            // '127.0.0.1',
        ],
    ],

    // AzerothCore MySQL（直接读写）
    'mysql' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'keira',
        'password' => '<db-password>',
        // Keira 默认会用 acore_world
        'database' => 'acore_world',
        'charset' => 'utf8mb4',
    ],

    // SQLite 查询（用于图标/法术名等离线数据）。如果不需要可先留空。
    'sqlite' => [
        // 建议把 Keira3 的 sqlite.db / item_display.db 复制到本目录 web/assets/ 下（构建前端时也会带上 assets）
        'sqlite_path' => __DIR__ . '/../web/assets/sqlite.db',
        'sqlite_item3d_path' => __DIR__ . '/../web/assets/item_display.db',
    ],
];
