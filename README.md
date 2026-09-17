# 🗄️ Laravel Database Visualizer & Route Query Tracer

A lightweight, zero-configuration local development package for Laravel to **visualize database schema structure** and **trace SQL execution flows for any HTTP route** in real time.

---

## ✨ Features

- 🗺️ **Schema Visualizer**: Interactive ER diagrams, table relationships, index listings, and column metadata.
- ⚡ **Route Query Tracer**: Replay any Web or API route (`GET`, `POST`, `PUT`, `DELETE`) and watch SQL queries fire live.
- 🔄 **Real-Time Data Transfer Flow**: Visual animation showing how data moves between your application routes and database tables.
- 🔑 **Automatic Zero-Config Auth Bypass**: Automatically logs in as the first available user in `local` environment without pasting cookies or headers.
- 🛡️ **Automatic CSRF Token Handling**: Post form data or trigger state-mutating endpoints cleanly without `419 Page Expired` errors.
- 📊 **Read / Write / Mutate Breakdown**: Instant counts and timing analysis for `SELECT`, `INSERT`, `UPDATE`, and `DELETE` queries per table.

---

## 📸 Overview & Functionality

```
+-----------------------------------------------------------------------------------+
|  Laravel Application (APP_ENV=local)                                               |
|  http://127.0.0.1:8000                                                            |
+-----------------------------------------------------------------------------------+
                                         ▲
                                         │  Proxy HTTP Request + X-DB-Tracer
                                         │  (Auto Auth + Auto CSRF Token)
                                         ▼
+-----------------------------------------------------------------------------------+
|  Route Query Tracer & Visualizer                                                  |
|  http://127.0.0.1:7331/tracer.html                                                 |
+-----------------------------------------------------------------------------------+
|  [ GET ]  /admin/bookings                                           [ TRACE RUN ] |
+-----------------------------------------------------------------------------------+
|                                                                                   |
|  [ Request Flow Visualizer ]                                                      |
|   Route [/admin/bookings] ─── (SELECT * FROM users) ────> [ users ]              |
|                           ─── (SELECT * FROM bookings) ─> [ bookings ]           |
|                                                                                   |
|  [ Execution Timeline & SQL Queries ]                                             |
|   #1  0.8ms   SELECT * FROM `users` WHERE `id` = 1 LIMIT 1                        |
|   #2  1.4ms   SELECT `bookings`.*, `users`.`name` FROM `bookings` ...             |
+-----------------------------------------------------------------------------------+
```

---

## 🚀 Quick Start

### 1. Installation

Add to your Laravel application in local development:

```bash
composer require --dev database-visualizer/laravel
```

### 2. Run the Visualizer & Tracer

Run the Artisan command from your project terminal:

```bash
php artisan db:viewer
```

The command will automatically launch the interactive dashboard at:
👉 **`http://127.0.0.1:7331`** (Schema Visualizer)  
👉 **`http://127.0.0.1:7331/tracer.html`** (Route Query Tracer)

---

## 🔍 How to Use the Route Query Tracer

1. Make sure your main Laravel application is running (e.g. `php artisan serve` on `http://127.0.0.1:8000`).
2. Run `php artisan db:viewer`.
3. Open `http://127.0.0.1:7331/tracer.html`.
4. Enter any route URL from your application (e.g., `/api/profile` or `/admin/dashboard`).
5. Click **Trace Request**.
6. View the exact execution flow:
   - **Table Cards**: Displays total query counts, reads, writes, and mutations per table.
   - **Data Flow Graph**: Animated particles demonstrating query execution order.
   - **Detailed SQL Timeline**: Full SQL code, bindings, execution times in milliseconds, and call sequence.

---

## 🛠️ Configuration & Options

Publish the configuration file (optional):

```bash
php artisan vendor:publish --tag=db-viewer-config
```

Config file (`config/db-viewer.php`):

```php
return [
    'host' => env('DB_VIEWER_HOST', '127.0.0.1'),
    'port' => (int) env('DB_VIEWER_PORT', 7331),

    'tracer' => [
        'enabled'         => env('DB_TRACER_ENABLED', true),
        'app_url'         => env('DB_TRACER_APP_URL', env('APP_URL', 'http://127.0.0.1:8000')),
        'timeout_seconds' => (int) env('DB_TRACER_TIMEOUT', 30),

        // Auto-authenticates as the first available user in local DB.
        // Set to a specific ID (e.g. 5) or 0 to disable.
        'auth_user_id'    => env('DB_TRACER_AUTH_USER_ID', 'auto'),
    ],
];
```

### Command Flags

```bash
# Custom port or host
php artisan db:viewer --port=8080 --host=0.0.0.0

# Specify custom DB connection
php artisan db:viewer --connection=sqlite

# Do not auto-open browser
php artisan db:viewer --no-open
```

---

## 🔒 Security & Safety

- The query tracer middleware is **strictly inactive** unless `APP_ENV=local`.
- Non-local environments return raw untouched HTTP responses.
- The visualizer server binds to `127.0.0.1` locally by default.
