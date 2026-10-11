<?php

namespace DatabaseVisualizer\Laravel\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Intercepts requests that carry the X-DB-Tracer header, automatically bypasses
 * auth + CSRF for local development, and appends a structured query log to the
 * response as the X-DB-Trace-Data header.
 *
 * Registered by DatabaseViewerServiceProvider in BOTH the `web` and `api` middleware
 * groups, positioned after StartSession and before VerifyCsrfToken via kernel priority.
 *
 * Safety guards — only activates when ALL of the following are true:
 *   1. APP_ENV = local
 *   2. Request carries header  X-DB-Tracer: 1
 */
class DbTracerMiddleware
{
    /** @var array<int, array<string, mixed>> */
    private array $queries = [];

    public function handle(Request $request, Closure $next): Response
    {
        // Hard safety guard — never run outside local.
        if (!app()->environment('local') || $request->header('X-DB-Tracer') !== '1') {
            return $next($request);
        }

        return $this->traceRequest($request, $next);
    }

    private function traceRequest(Request $request, Closure $next): Response
    {
        // ── 1. Auth bypass ─────────────────────────────────────────────────
        // Log in as an available user so auth middleware passes cleanly.
        // Tries configured ID, or automatically resolves the first user in the DB.
        $userId = config('db-viewer.tracer.auth_user_id');

        try {
            if ($userId === null || $userId === 'auto' || $userId === 0 || $userId === '0') {
                // Auto-resolve first user from user model / table if available
                $userModel = config('auth.providers.users.model', 'App\\Models\\User');
                if (class_exists($userModel)) {
                    $user = $userModel::first();
                    if ($user) {
                        Auth::login($user);
                    }
                }
            } elseif ((int) $userId > 0) {
                Auth::loginUsingId((int) $userId);
            }
        } catch (Throwable) {
            // User model / table doesn't exist or guard misconfigured; continue safely.
        }

        // ── 2. CSRF bypass ─────────────────────────────────────────────────
        // At this point (running after StartSession in the web group), the
        // session is live. Inject the real session CSRF token into the request
        // so VerifyCsrfToken sees a valid token and does not block us.
        try {
            $token = $request->session()->token();
            $request->merge(['_token' => $token]);
            $request->headers->set('X-CSRF-TOKEN', $token);
        } catch (Throwable) {
            // Session may not exist (e.g., api group, stateless routes) — fine.
        }

        // ── 3. Query capture ───────────────────────────────────────────────
        $startTime = hrtime(true);

        DB::listen(function (QueryExecuted $event) {
            $this->queries[] = [
                'sql'        => $event->sql,
                'bindings'   => $event->bindings,
                'time_ms'    => $event->time,
                'connection' => $event->connectionName,
                'type'       => $this->classifyQuery($event->sql),
                'tables'     => $this->extractTables($event->sql),
            ];
        });

        /** @var Response $response */
        $response = $next($request);

        $totalMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

        // Analyze queries for duplicate / N+1 patterns
        $nPlusOneData = $this->analyzeNPlusOne();

        $payload = json_encode([
            'url'         => $request->getRequestUri(),
            'method'      => $request->method(),
            'status'      => $response->getStatusCode(),
            'duration_ms' => $totalMs,
            'queries'     => $this->queries,
            'tables'      => $this->buildTableSummary(),
            'n_plus_one'  => $nPlusOneData,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Base64-encode so special characters don't break the header.
        $response->headers->set('X-DB-Trace-Data', base64_encode($payload));

        return $response;
    }

    /**
     * Classify a SQL statement as read, write, mutate, or other.
     *
     * @return 'read'|'write'|'mutate'|'other'
     */
    private function classifyQuery(string $sql): string
    {
        $trimmed = ltrim($sql);

        if (stripos($trimmed, 'SELECT') === 0) return 'read';
        if (stripos($trimmed, 'INSERT') === 0) return 'write';
        if (stripos($trimmed, 'UPDATE') === 0 || stripos($trimmed, 'DELETE') === 0) return 'mutate';

        return 'other';
    }

    /**
     * Extract all table names referenced in a SQL statement.
     *
     * @return string[]
     */
    private function extractTables(string $sql): array
    {
        $tables = [];

        if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
            foreach ($matches[1] as $table) {
                $tables[] = strtolower($table);
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Build a per-table summary of reads, writes, and mutates.
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

    /**
     * Analyze captured queries to detect duplicate executions and potential N+1 query problems.
     *
     * @return array{
     *   detected: bool,
     *   count: int,
     *   total_waste_queries: int,
     *   total_time_ms: float,
     *   alerts: list<array{sql: string, count: int, total_time_ms: float, tables: string[], fingerprint: string, suggestion: string}>
     * }
     */
    private function analyzeNPlusOne(): array
    {
        $threshold = max(2, (int) (function_exists('config') ? config('db-viewer.tracer.n_plus_one_threshold', 3) : 3));
        $groups = [];

        foreach ($this->queries as $index => $query) {
            $normalized = $this->normalizeSql($query['sql']);
            $fingerprint = md5(($query['connection'] ?? 'default') . ':' . strtolower($normalized));

            if (!isset($groups[$fingerprint])) {
                $groups[$fingerprint] = [
                    'fingerprint'   => $fingerprint,
                    'sql'           => $query['sql'],
                    'normalized'    => $normalized,
                    'count'         => 0,
                    'total_time_ms' => 0.0,
                    'tables'        => $query['tables'] ?? [],
                    'indices'       => [],
                ];
            }

            $groups[$fingerprint]['count']++;
            $groups[$fingerprint]['total_time_ms'] += (float) ($query['time_ms'] ?? 0);
            $groups[$fingerprint]['indices'][] = $index;
        }

        $alerts = [];
        $totalWaste = 0;
        $totalN1Time = 0.0;

        foreach ($groups as $fingerprint => $group) {
            $isDuplicate = $group['count'] > 1;
            $isNPlusOne  = $group['count'] >= $threshold;

            // Annotate individual queries in the timeline
            foreach ($group['indices'] as $idx) {
                $this->queries[$idx]['fingerprint']     = $fingerprint;
                $this->queries[$idx]['duplicate_count'] = $group['count'];
                $this->queries[$idx]['is_duplicate']    = $isDuplicate;
                $this->queries[$idx]['is_n_plus_one']   = $isNPlusOne;
            }

            if ($isNPlusOne) {
                $totalWaste += ($group['count'] - 1);
                $totalN1Time += $group['total_time_ms'];

                $tablesDesc = !empty($group['tables']) ? implode(', ', $group['tables']) : 'table';
                $alerts[] = [
                    'sql'           => $group['sql'],
                    'count'         => $group['count'],
                    'total_time_ms' => round($group['total_time_ms'], 2),
                    'tables'        => $group['tables'],
                    'fingerprint'   => $fingerprint,
                    'suggestion'    => "Executed {$group['count']} times on {$tablesDesc}. Consider eager loading using ->with(...) or batch querying.",
                ];
            }
        }

        // Sort alerts by count descending (highest offenders first)
        usort($alerts, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'detected'            => !empty($alerts),
            'count'               => count($alerts),
            'total_waste_queries' => $totalWaste,
            'total_time_ms'       => round($totalN1Time, 2),
            'alerts'              => $alerts,
        ];
    }

    /**
     * Normalize SQL query for pattern fingerprinting.
     */
    private function normalizeSql(string $sql): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql) ?? '');
        // Collapse multiple IN parameter placeholders: IN (?, ?, ?) -> IN (?)
        return preg_replace('/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $normalized) ?? $normalized;
    }
}

