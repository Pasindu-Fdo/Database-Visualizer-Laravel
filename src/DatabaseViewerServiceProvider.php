<?php
namespace DatabaseVisualizer\Laravel;

use DatabaseVisualizer\Laravel\Commands\DatabaseViewerCommand;
use DatabaseVisualizer\Laravel\Http\Middleware\DbTracerMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\ServiceProvider;

class DatabaseViewerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-viewer.php', 'db-viewer');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DatabaseViewerCommand::class,
            ]);
            $this->publishes([__DIR__.'/../config/db-viewer.php' => config_path('db-viewer.php')], 'db-viewer-config');
        }

        // Register tracer middleware globally if Kernel exists
        if ($this->app->bound(Kernel::class)) {
            $kernel = $this->app->make(Kernel::class);
            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(DbTracerMiddleware::class);
            }
            $this->insertIntoMiddlewarePriority($kernel);
        }
    }

    /**
     * Try to insert DbTracerMiddleware after StartSession in middleware priority array.
     */
    protected function insertIntoMiddlewarePriority($kernel): void
    {
        try {
            $ref = new \ReflectionProperty(get_class($kernel), 'middlewarePriority');
            $ref->setAccessible(true);
            $priority = $ref->getValue($kernel) ?? [];

            // Place DbTracerMiddleware right after StartSession if it exists
            $startSessionIndex = array_search(\Illuminate\Session\Middleware\StartSession::class, $priority);
            if ($startSessionIndex !== false) {
                array_splice($priority, $startSessionIndex + 1, 0, [DbTracerMiddleware::class]);
            } else {
                $priority[] = DbTracerMiddleware::class;
            }

            $ref->setValue($kernel, $priority);
        } catch (\Throwable $e) {
            // Ignore reflection failures on unsupported versions
        }
    }
}
