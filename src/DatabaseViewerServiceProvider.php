<?php
namespace DatabaseVisualizer\Laravel;

use DatabaseVisualizer\Laravel\Commands\DatabaseViewerCommand;
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
            $this->commands([DatabaseViewerCommand::class]);
            $this->publishes([__DIR__.'/../config/db-viewer.php' => config_path('db-viewer.php')], 'db-viewer-config');
        }
    }
}
