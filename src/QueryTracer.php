<?php

namespace DatabaseVisualizer\Laravel;

/**
 * Sends a traced HTTP request to the host Laravel application and parses
 * the query log returned in the X-DB-Trace-Data response header.
 *
 * This class runs inside the viewer's standalone PHP server (server.php)
 * and therefore cannot use any Laravel/Illuminate helpers.
 */
class QueryTracer
{
    public function __construct(
        private readonly string $appUrl,
        private readonly int    $timeoutSeconds = 30,
    ) {}

    /**
     * Trace a single request and return a structured result.
     *
     * @param  string $path    e.g. "/api/profile" or "/admin/dashboard"
     * @param  string $method  HTTP verb (GET, POST, PUT, DELETE, PATCH)
     * @param  array<string,string> $headers  Extra request headers to forward
     * @param  string $body    Raw request body (for POST/PUT)
     * @param  string $cookies Cookie string to forward (e.g. "laravel_session=abc123")
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   url: string,
     *   method: string,
     *   status: int,
     *   duration_ms: int,
     *   queries: list<array{sql:string,bindings:array,time_ms:float,connection:string,type:string,tables:string[]}>,
     *   tables: array<string,array{reads:int,writes:int,mutates:int,query_count:int}>,
     *   sequence: list<array{step:int,table:string,type:string,sql:string,bindings:array,time_ms:float}>
     * }
     */
    public function trace(
        string $path,
        string $method  = 'GET',
        array  $headers = [],
        string $body    = '',
        string $cookies = '',
    ): array {
        $url    = rtrim($this->appUrl, '/') . '/' . ltrim($path, '/');
        $method = strtoupper($method);

        $requestHeaders = array_merge([
            'X-DB-Tracer: 1',
            'Accept: application/json, text/html, */*',
            'User-Agent: DB-Visualizer-Tracer/1.0',
            'X-Requested-With: XMLHttpRequest',
        ], $headers);

        // Forward cookies so authenticated routes can be traced.
        if ($cookies !== '') {
            $requestHeaders[] = 'Cookie: ' . $cookies;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $requestHeaders),
                'content'       => $body,
                'timeout'       => $this->timeoutSeconds,
                'ignore_errors' => true,   // Don't throw on 4xx / 5xx
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $startTime = hrtime(true);

        $responseBody = @file_get_contents($url, false, $context);

        $durationMs = (int) round((hrtime(true) - $startTime) / 1_000_000);

        if ($responseBody === false) {
            return [
                'ok'    => false,
                'error' => "Could not connect to {$url}. Make sure php artisan serve is running.",
                'url'   => $url,
                'method' => $method,
                'status' => 0,
                'duration_ms' => $durationMs,
                'queries' => [],
                'tables'  => [],
                'sequence' => [],
            ];
        }

        // Parse HTTP status from $http_response_header (populated by file_get_contents).
        $statusCode = $this->parseStatusCode($http_response_header ?? []);

        // Extract the trace payload from the special header.
        $traceData = $this->extractTraceHeader($http_response_header ?? []);

        if ($traceData === null) {
            // Provide specific hints for common failure scenarios.
            $hint = match (true) {
                $statusCode === 302 || $statusCode === 301
                    => 'The route redirected (likely an auth redirect to /login). ' .
                       'Provide your session cookie in the Cookie field so the tracer can authenticate.',
                $statusCode === 404
                    => 'Route not found (404). Check the path and method.',
                $statusCode === 405
                    => 'Method not allowed (405). This route exists but does not accept ' . $method . '.',
                $statusCode === 419
                    => 'CSRF token mismatch (419). Add your XSRF-TOKEN cookie to authenticate.',
                $statusCode === 500
                    => 'The app threw a 500 error. Check your Laravel logs.',
                default
                    => 'The app did not return X-DB-Trace-Data. ' .
                       'Make sure the package is installed (composer install) and APP_ENV=local.',
            };

            return [
                'ok'    => false,
                'error' => $hint,
                'url'   => $url,
                'method' => $method,
                'status' => $statusCode,
                'duration_ms' => $durationMs,
                'queries' => [],
                'tables'  => [],
                'sequence' => [],
            ];
        }

        $queries  = $traceData['queries']  ?? [];
        $tables   = $traceData['tables']   ?? [];
        $sequence = $this->buildSequence($queries);

        return [
            'ok'          => true,
            'url'         => $url,
            'method'      => $method,
            'status'      => $statusCode,
            'duration_ms' => $traceData['duration_ms'] ?? $durationMs,
            'queries'     => $queries,
            'tables'      => $tables,
            'sequence'    => $sequence,
        ];
    }

    /**
     * Parse the HTTP status code from the response header array returned by
     * file_get_contents (i.e., $http_response_header).
     *
     * @param  string[] $headers
     */
    private function parseStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * Extract and JSON-decode the X-DB-Trace-Data header value.
     *
     * @param  string[] $headers
     * @return array<string,mixed>|null
     */
    private function extractTraceHeader(array $headers): ?array
    {
        foreach ($headers as $header) {
            if (stripos($header, 'X-DB-Trace-Data:') === 0) {
                $encoded = trim(substr($header, strlen('X-DB-Trace-Data:')));
                $decoded = base64_decode($encoded, true);

                if ($decoded === false) {
                    return null;
                }

                $data = json_decode($decoded, true);

                return is_array($data) ? $data : null;
            }
        }

        return null;
    }

    /**
     * Convert the flat query list into an ordered sequence of table interactions,
     * suitable for rendering the animated flow diagram.
     *
     * @param  list<array<string,mixed>> $queries
     * @return list<array{step:int,table:string,type:string,sql:string,bindings:array,time_ms:float}>
     */
    private function buildSequence(array $queries): array
    {
        $sequence = [];
        $step     = 1;

        foreach ($queries as $query) {
            $tables = $query['tables'] ?? [];

            if (empty($tables)) {
                // Include queries with no detectable table (e.g. SHOW, SET) under a synthetic name.
                $tables = ['[raw]'];
            }

            foreach ($tables as $table) {
                $sequence[] = [
                    'step'     => $step++,
                    'table'    => $table,
                    'type'     => $query['type']    ?? 'other',
                    'sql'      => $query['sql']      ?? '',
                    'bindings' => $query['bindings'] ?? [],
                    'time_ms'  => $query['time_ms']  ?? 0,
                ];
            }
        }

        return $sequence;
    }
}
