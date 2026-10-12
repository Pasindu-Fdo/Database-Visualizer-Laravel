# Laravel Database Visualizer & Route Query Tracer

A lightweight, zero-configuration local development package for Laravel to **visualize database schema structure** and **trace SQL execution flows for any HTTP route** in real time.

---

## Features

- **Schema Visualizer**: Interactive ER diagrams, table relationships, index listings, and column metadata.
- **Route Query Tracer**: Replay any Web or API route (`GET`, `POST`, `PUT`, `DELETE`, `PATCH`) with automatic route autocomplete.
- **Live Query Execution Flow**: Visual sequence track showing execution order, table touches, and SQL operations.
- **N+1 Query & Duplicate Detection**: Automatically identifies repeated identical queries with optimization suggestions and one-click filtering.
- **Slow Query & Full Table Scan Warnings**: Flags queries exceeding threshold times and runs `EXPLAIN` to spot missing indexes.
- **1-Click IDE Jump Links**: Direct deep links on query callers into VS Code, PhpStorm, Cursor, or Sublime Text.
- **Zero-Config Auth & CSRF Handling**: Automatically authenticates across guards without overriding persistent browser sessions, preventing `403` and `419` errors.
- **Read / Write / Mutate Breakdown**: Instant counts and timing analysis for `SELECT`, `INSERT`, `UPDATE`, and `DELETE` queries per table.

---

## Overview & Functionality

```
+-----------------------------------------------------------------------------------+
|  Laravel Application (APP_ENV=local)                                               |
|  http://127.0.0.1:8000                                                            |
+-----------------------------------------------------------------------------------+
                                         ▲
                                         │  Proxy HTTP Request + X-DB-Tracer
                                         │  (In-Memory Auth + Auto CSRF Token)
                                         ▼
+-----------------------------------------------------------------------------------+
|  Route Query Tracer & Visualizer                                                  |
|  http://127.0.0.1:7331/tracer                                                      |
+-----------------------------------------------------------------------------------+
|  [ GET ]  /admin/bookings                                           [ TRACE ]     |
+-----------------------------------------------------------------------------------+
|                                                                                   |
|  [ Execution Sequence & Timeline ]                                                |
|   1. sessions          SELECT    0.57ms   [ public/index.php:20 ↗ ]               |
|   2. audit_logs        SELECT    0.49ms   [ AuditLogController.php:30 ↗ ]         |
|   3. users             SELECT    0.41ms   [ AuditLogController.php:30 ↗ ]         |
|   4. sessions          INSERT    6.04ms   [ public/index.php:20 ↗ ]               |
|                                                                                   |
|  [ N+1 & Query Performance Insights ]                                             |
|   ⚡ 1 N+1 pattern detected • 4 duplicate executions • Full table scan warning    |
+-----------------------------------------------------------------------------------+
```

---

## Quick Start

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
- **`http://127.0.0.1:7331`** (Schema Visualizer)  
- **`http://127.0.0.1:7331/tracer`** (Route Query Tracer)

---

## How to Use the Route Query Tracer

1. Make sure your main Laravel application is running (e.g. `php artisan serve` on `http://127.0.0.1:8000`).
2. Run `php artisan db:viewer`.
3. Open `http://127.0.0.1:7331/tracer`.
4. Enter or select a route URL from autocomplete (e.g., `/api/profile` or `/admin/bookings`).
5. Click **Trace**.
6. View the execution flow:
   - **Sequence Flow**: Chronological step-by-step query flow with table names, types, timing, and caller origin links.
   - **N+1 Problem Alert**: Instant banner highlighting duplicate queries with suggestions and toggle filter.
   - **Tables Touched Summary**: Per-table read, write, and mutation aggregations.
   - **Query Timing Bar Graph**: Visual proportional breakdown of execution time across all queries.

---

## Configuration & Options

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
        // - 'auto' or null (default): First user found in the DB.
        // - Specific integer (e.g. 1): Specific user ID.
        // - 0 or false: Disables auto-authentication.
        'auth_user_id'    => env('DB_TRACER_AUTH_USER_ID', 'auto'),

        // Threshold for flagging repeated queries as N+1 issues (default: 3)
        'n_plus_one_threshold' => (int) env('DB_TRACER_N_PLUS_ONE_THRESHOLD', 3),

        // Preferred IDE for code origin jump links ('vscode', 'phpstorm', 'cursor', 'subl')
        'ide' => env('DB_VIEWER_IDE', 'vscode'),

        // Threshold (in milliseconds) to flag queries as slow (default: 100ms)
        'slow_query_threshold_ms' => (float) env('DB_TRACER_SLOW_QUERY_THRESHOLD_MS', 100.0),

        // Run EXPLAIN on slow SELECT queries to detect full table scans
        'explain_slow_queries' => env('DB_TRACER_EXPLAIN_SLOW_QUERIES', true),
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

## Security & Safety

- The query tracer middleware is **strictly inactive** unless `APP_ENV=local`.
- Non-local environments return raw untouched HTTP responses.
- The visualizer server binds to `127.0.0.1` locally by default.
