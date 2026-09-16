# Laravel Database Viewer

Install for development and open a read-only visualization of the configured database:

```bash
composer require --dev database-visualizer/laravel
php artisan db:viewer
```

Options include `--connection=mysql`, `--host=127.0.0.1`, `--port=7331`, and `--no-open`. The server binds to localhost by default and stops with Ctrl+C. Publish configuration with `php artisan vendor:publish --tag=db-viewer-config`.
