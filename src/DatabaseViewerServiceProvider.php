<?php
namespace DatabaseVisualizer\Laravel;

use DatabaseVisualizer\Laravel\Commands\DatabaseViewerCommand;
use DatabaseVisualizer\Laravel\Http\Middleware\DbTracerMiddleware;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;

class DatabaseViewerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/db-viewer.php', 'db-viewer');
    }

    public function boot(): void
    {
        // Auto-register the tracer middleware globally.
        // The middleware itself only activates in local env with the special header,
        // so there is zero performance cost in any other environment.
        if ($this->app->bound(Kernel::class)) {
            $this->app[Kernel::class]->pushMiddleware(DbTracerMiddleware::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([DatabaseViewerCommand::class]);
            $this->publishes([__DIR__.'/../config/db-viewer.php' => config_path('db-viewer.php')], 'db-viewer-config');
        }
    }
}
