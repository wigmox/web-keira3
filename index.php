<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';

keira_enforce_ip_allowlist();

$base = keira_base_path();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$path = is_string($path) ? $path : '';

// 处理登出
if ($path === $base . '/logout') {
    keira_logout();
    header('Location: ' . $base . '/');
    exit;
}

// 处理表单登录（壳登录页）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($path === $base . '/' || $path === $base)) {
    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (keira_auth_login($username, $password)) {
        keira_set_logged_in();
        header('Location: ' . $base . '/');
        exit;
    }

    $loginError = '用户名或密码错误';
}

if (!keira_is_logged_in()) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');

    $err = isset($loginError) ? '<div style="color:#c00;margin-top:8px;">' . htmlspecialchars($loginError, ENT_QUOTES) . '</div>' : '';

    echo '<!doctype html><html lang="zh-CN"><head>';
    echo '<meta charset="utf-8" />';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
    echo '<title>Keira 登录</title>';
    echo '</head><body style="font-family:system-ui,Segoe UI,Arial;max-width:420px;margin:10vh auto;padding:16px;">';
    echo '<h2 style="margin:0 0 12px;">Keira</h2>';
    echo '<form method="post" action="' . htmlspecialchars($base . '/', ENT_QUOTES) . '">';
    echo '<label>用户名<br><input name="username" autocomplete="username" style="width:100%;padding:8px;" /></label><br><br>';
    echo '<label>密码<br><input type="password" name="password" autocomplete="current-password" style="width:100%;padding:8px;" /></label><br>'; 
    echo $err;
    echo '<button type="submit" style="margin-top:12px;padding:10px 14px;">登录</button>';
    echo '</form>';
    echo '</body></html>';
    exit;
}

// 已登录：输出 Keira3 Web 构建产物的 index.html（放在 /keira/web/index.html），并让其使用 /keira/api
$indexPath = __DIR__ . '/web/index.html';
if (!file_exists($indexPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Keira 前端未部署：缺少 web/index.html\n";
    echo "请先构建 Keira3（web 模式）并把 dist 产物复制到 /keira/web/ 目录。\n";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
readfile($indexPath);
