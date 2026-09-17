<?php

namespace DatabaseVisualizer\Laravel\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intercepts requests that carry the X-DB-Tracer header and appends a
 * structured query log to the response as the X-DB-Trace-Data header.
 *
 * This middleware is auto-registered by DatabaseViewerServiceProvider and is
 * completely transparent on every normal request. It only activates when:
 *   1. The APP_ENV is "local" (hard-coded safety guard), and
 *   2. The incoming request carries the header  X-DB-Tracer: 1
 */
class DbTracerMiddleware
{
    /** @var array<int, array<string, mixed>> */
    private array $queries = [];

    public function handle(Request $request, Closure $next): Response
    {
        // Safety: never run outside local environment.
        if (app()->environment('local') && $request->header('X-DB-Tracer') === '1') {
            return $this->traceRequest($request, $next);
        }

        return $next($request);
    }

    private function traceRequest(Request $request, Closure $next): Response
    {
        $startTime = hrtime(true);

        // Listen for every query fired during this request.
        DB::listen(function (QueryExecuted $event) {
            $this->queries[] = [
                'sql'         => $event->sql,
                'bindings'    => $event->bindings,
                'time_ms'     => $event->time,
                'connection'  => $event->connectionName,
                'type'        => $this->classifyQuery($event->sql),
                'tables'      => $this->extractTables($event->sql),
            ];
        });

        /** @var Response $response */
        $response = $next($request);

        $totalMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

        $payload = json_encode([
            'url'         => $request->getRequestUri(),
            'method'      => $request->method(),
            'status'      => $response->getStatusCode(),
            'duration_ms' => $totalMs,
            'queries'     => $this->queries,
            'tables'      => $this->buildTableSummary(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Attach trace data as a response header so the proxy can read it.
        // We base64-encode to avoid any header-encoding issues with special chars.
        $response->headers->set('X-DB-Trace-Data', base64_encode($payload));

        return $response;
    }

    /**
     * Classify an SQL statement as read, write, or mutate.
     *
     * @return 'read'|'write'|'mutate'|'other'
     */
    private function classifyQuery(string $sql): string
    {
        $trimmed = ltrim($sql);

        if (stripos($trimmed, 'SELECT') === 0) {
            return 'read';
        }

        if (stripos($trimmed, 'INSERT') === 0) {
            return 'write';
        }

        if (stripos($trimmed, 'UPDATE') === 0 || stripos($trimmed, 'DELETE') === 0) {
            return 'mutate';
        }

        return 'other';
    }

    /**
     * Extract all table names referenced in an SQL statement.
     *
     * Handles: FROM, JOIN, INSERT INTO, UPDATE, DELETE FROM.
     *
     * @return string[]
     */
    private function extractTables(string $sql): array
    {
        $tables = [];

        // Patterns that precede a table name in SQL.
        $patterns = [
            '/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+[`"]?(\w+)[`"]?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $sql, $matches)) {
                foreach ($matches[1] as $table) {
                    $tables[] = strtolower($table);
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Build a per-table summary: how many reads, writes, and mutates each table received.
     *
     * @return array<string, array{reads: int, writes: int, mutates: int, query_count: int}>
     */
    private function buildTableSummary(): array
    {
        $summary = [];

        foreach ($this->queries as $query) {
            foreach ($query['tables'] as $table) {
                if (!isset($summary[$table])) {
                    $summary[$table] = ['reads' => 0, 'writes' => 0, 'mutates' => 0, 'query_count' => 0];
                }

                $summary[$table]['query_count']++;

                match ($query['type']) {
                    'read'   => $summary[$table]['reads']++,
                    'write'  => $summary[$table]['writes']++,
                    'mutate' => $summary[$table]['mutates']++,
                    default  => null,
                };
            }
        }

        return $summary;
    }
}
