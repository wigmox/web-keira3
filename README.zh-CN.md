# Keira（B/S 版）部署与运维说明

本项目是在 Keira3（AzerothCore 数据库编辑器）的基础上，为 Apache + PHP 场景提供的 **B/S 部署包装**：

- 前端：Keira3（Nx + Angular）构建出的静态站点
- 后端：轻量 PHP 壳（登录、会话、转发 API、SQLite 辅助查询）
- 部署前缀：默认挂载在 `/keira/`（可配置）

目标访问示例：`https://example.com/keira/`

## 项目简介

Keira3 原本以桌面端（Electron）为主。本项目通过“静态前端 + PHP API”的方式，让它可以在浏览器中使用，并支持：

- 单用户登录保护（基于 PHP session cookie）
- MySQL 查询/写入（连接 AzerothCore world 数据库）
- SQLite 查询（用于图标/法术名等离线数据）
- 前端 i18n 中文化治理（尽量避免英文直出/原始 key 直出）

## 基于 Keira3 做了哪些修改

前端（Keira3）侧：

- 路径适配：支持以 `/keira/` 为 baseHref，静态资源走 `/keira/web/`
- i18n 治理：
  - 字段列头/label 统一走翻译 key
  - selector（位掩码、单值枚举）支持 `i18nKey` 命名空间
  - 缺失翻译时回退原文，并在中文环境下对 token 做可读化兜底

后端（PHP 壳）侧：

- 提供 `/keira/api/*` JSON API（auth/mysql/sqlite）
- 单用户账号登录（用户名 + password_hash）
- 会话安全：登录时 `session_regenerate_id(true)`，cookie `HttpOnly`，可配置 `SameSite`
- 空闲超时：可配置 `idle_timeout_seconds`
- 登录限流：按 IP + 时间窗的简单频率限制，返回 429 防暴力猜解
- 安全防护：阻止直接访问 `config/` 与 `lib/`

## 目录结构

运行时目录（部署后需要存在的结构）：

```text
keira/
  index.php                 # PHP 壳：登录页 + SPA deep-link
  .htaccess                 # 路由分发与保护规则
  api/
    index.php               # /keira/api/* 入口
    .htaccess               # API 路由分发
  lib/
    bootstrap.php           # session、JSON、限流等基础能力
    auth.php                # 登录/登出
    db_mysql.php            # MySQL 查询封装
    db_sqlite.php           # SQLite 查询封装
  config/
    config.php              # 生产配置（不要暴露/不要随前端覆盖）
    config.example.php      # 配置模板
  web/
    index.html              # Keira3 Web 构建产物
    assets/
      i18n/
        zh.json
        en.json
      sqlite.db             # 可选：离线 SQLite 数据库（按需）
      item_display.db       # 可选：3D/展示相关 SQLite 数据库（按需）
```

说明：服务器 **不需要 Keira3 源码** 也可以运行；源码只用于重新构建前端。

## 服务器环境要求

### Web 服务器

- Apache 2.4+
- 开启 `mod_rewrite`
- 允许 `.htaccess` 生效（对应目录 `AllowOverride All`）

### PHP

- PHP 8.1+（建议与 Apache 兼容的 TS 版本，使用 mod_php 加载）
- 必需扩展：
  - `mysqli`
- 可选但强烈建议（用于离线数据查询）：
  - `sqlite3`
  - `pdo_sqlite`

Windows 场景提示：如果启用 sqlite 扩展后仍提示 “Unable to load dynamic library”，通常是扩展依赖 DLL 不在 Apache 进程的 DLL 搜索路径上，需要将对应依赖（如 `libsqlite3.dll`）放到 Apache 可搜索的位置（例如 `Apache/bin`）或调整环境变量。

### HTTPS（强烈建议）

- 建议使用 HTTPS
- 配置文件中的 `app.session.cookie_secure` 仅在 HTTPS 环境下才应为 `true`

## 配置

1) 复制配置模板：

- `config/config.example.php` → `config/config.php`

2) 生成并填写登录密码哈希：

```bash
php -r "echo password_hash('yourPassword', PASSWORD_DEFAULT), PHP_EOL;"
```

3) 在 `config/config.php` 中配置：

- `app.base_path`（默认 `/keira`）
- `app.auth.username` / `app.auth.password_hash`
- `mysql.*`（AzerothCore MySQL 连接信息）
- `sqlite.sqlite_path` / `sqlite.sqlite_item3d_path`（可选）
- `app.session.*`（cookie 策略、空闲超时）
- `app.rate_limit.login.*`（登录限流阈值）
- `app.allowed_ips`（可选来源白名单，支持单个 IP 与网段）
- `app.trusted_proxies`（仅这些地址提供的 `X-Forwarded-For` 会被采信）

未配置安全项时使用默认值：登录 900 秒内最多 5 次，会话空闲 1800 秒失效。
公网部署还应配置 `app.allowed_ips`，并使用只具备所需库权限的非 root 数据库账号。

## 部署步骤（首次）

1) 放置本项目（PHP 壳）到 Web 根目录下的一个子目录，例如 `/keira/`。

2) 在服务器上确保 Apache + PHP 已正确工作，并满足“服务器环境要求”。

3) 构建并发布 Keira3 前端静态资源到 `keira/web/`。

构建机需要有：Node.js + npm（以及 Keira3 源码仓库）。在 Keira3 源码目录执行：

```bash
# 产物输出到 dist/browser（示例，实际以项目脚本为准）
npm run build -- -c dev-web
```

然后把构建产物（`dist/browser/*`）复制/同步到运行目录的 `keira/web/`。

4) 打开浏览器访问：`/keira/`，使用单用户账号登录。

## 更新步骤

### 更新前端（i18n/页面/组件等）

1) 在构建机重新构建 Keira3
2) 仅更新 `keira/web/`（覆盖静态资源）
3) 建议强制刷新浏览器缓存（Ctrl+F5）

提示：不要用构建产物覆盖 `keira/config/config.php`。

### 更新后端（PHP 壳）

1) 更新 `keira/index.php`、`keira/api/`、`keira/lib/`、`.htaccess`
2) 若开启了 OPcache 或需要立即生效，可重启 Apache

## API 约定

- 认证：
  - `GET /keira/api/auth/me`
  - `POST /keira/api/auth/login`（JSON：`{username,password}`）
  - `POST /keira/api/auth/logout`
- MySQL：
  - `POST /keira/api/mysql/connect`
  - `POST /keira/api/mysql/query`（JSON：`{queryString}`）
- SQLite（可选）：
  - `POST /keira/api/sqlite/query`（JSON：`{queryString, db: 'main'|'item3d'}`）

所有非 auth 接口都要求已登录（未登录返回 401）。
