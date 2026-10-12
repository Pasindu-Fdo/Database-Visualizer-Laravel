<?php
namespace DatabaseVisualizer\Laravel\Commands;

use DatabaseVisualizer\Laravel\SchemaInspector;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class DatabaseViewerCommand extends Command
{
    protected $signature = 'db:viewer {--host=} {--port=} {--connection=} {--no-open : Do not open a browser}';
    protected $description = 'Open a read-only visual viewer for the project database';

    public function handle(SchemaInspector $inspector): int
    {
        $host = $this->option('host') ?: config('db-viewer.host', '127.0.0.1');
        $port = (int) ($this->option('port') ?: config('db-viewer.port', 7331));

        // Write schema snapshot.
        $schemaFile = storage_path('framework/cache/db-viewer-schema.json');
        if (!is_dir(dirname($schemaFile))) mkdir(dirname($schemaFile), 0755, true);
        file_put_contents($schemaFile, json_encode($inspector->inspect($this->option('connection')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Write environment snapshot for the Route Tracer UI.
        // This lets the frontend auto-detect APP_URL without any user configuration.
        $envFile = storage_path('framework/cache/db-viewer-env.json');
        $tracerEnabled = config('db-viewer.tracer.enabled', true);
        file_put_contents($envFile, json_encode([
            'app_url'         => config('db-viewer.tracer.app_url', config('app.url', 'http://127.0.0.1:8000')),
            'tracer_enabled'  => $tracerEnabled,
            'timeout_seconds' => config('db-viewer.tracer.timeout_seconds', 30),
            'auth_user_id'    => config('db-viewer.tracer.auth_user_id', 1),
            'ide'             => config('db-viewer.tracer.ide', 'vscode'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Write routes snapshot for the Route Tracer autocomplete dropdown.
        $routesFile = storage_path('framework/cache/db-viewer-routes.json');
        file_put_contents($routesFile, json_encode($this->collectRoutes(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $public = realpath(__DIR__.'/../../resources/public');
        $router = $public.'/server.php';
        $process = new Process(
            [PHP_BINARY, '-S', "$host:$port", '-t', $public, $router],
            null,
            array_merge($_ENV, [
                'DB_VIEWER_SCHEMA_FILE' => $schemaFile,
                'DB_VIEWER_ENV_FILE'    => $envFile,
                'DB_VIEWER_ROUTES_FILE' => $routesFile,
            ])
        );

        $process->setTimeout(null);
        $process->start();

        $url = "http://$host:$port";
        $this->info("Database Viewer:  $url");
        $this->info("Route Tracer:     $url/tracer.html");
        $this->line('Read-only development server. Press Ctrl+C to stop.');

        if (!$this->option('no-open')) $this->openBrowser($url);

        foreach ($process as $type => $data) {
            if ($this->output->isVerbose()) $this->output->write($data);
        }

        return self::SUCCESS;
    }

    private function openBrowser(string $url): void
    {
        $command = PHP_OS_FAMILY === 'Darwin'
            ? ['open', $url]
            : (PHP_OS_FAMILY === 'Windows' ? ['cmd', '/c', 'start', '', $url] : ['xdg-open', $url]);

        try { (new Process($command))->start(); } catch (\Throwable) { $this->line("Open $url in your browser."); }
    }

    /**
     * Collect routes registered in the host Laravel application.
     *
     * @return list<array{uri: string, methods: list<string>, name: ?string, action: string}>
     */
    protected function collectRoutes(): array
    {
        try {
            $router = $this->laravel->make('router');
            $routes = [];

            foreach ($router->getRoutes()->getRoutes() as $route) {
                $uri = '/' . ltrim($route->uri(), '/');

                // Filter internal development and telemetry endpoints
                if (
                    str_starts_with($uri, '/_ignition') ||
                    str_starts_with($uri, '/sanctum/csrf-cookie') ||
                    str_starts_with($uri, '/telescope') ||
                    str_starts_with($uri, '/horizon')
                ) {
                    continue;
                }

                $methods = array_values(array_diff($route->methods(), ['HEAD']));
                $action = $route->getActionName();
                $actionName = is_string($action) ? $action : 'Closure';

                $routes[] = [
                    'uri'     => $uri,
                    'methods' => $methods,
                    'name'    => $route->getName(),
                    'action'  => $actionName,
                ];
            }

            usort($routes, fn ($a, $b) => strcmp($a['uri'], $b['uri']));

            return $routes;
        } catch (\Throwable) {
            return [];
        }
    }
}
