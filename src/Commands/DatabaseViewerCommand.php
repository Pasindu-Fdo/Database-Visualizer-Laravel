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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $public = realpath(__DIR__.'/../../resources/public');
        $router = $public.'/server.php';
        $process = new Process(
            [PHP_BINARY, '-S', "$host:$port", '-t', $public, $router],
            null,
            array_merge($_ENV, [
                'DB_VIEWER_SCHEMA_FILE' => $schemaFile,
                'DB_VIEWER_ENV_FILE'    => $envFile,
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
}
