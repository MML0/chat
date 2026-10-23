<?php
// Request performance per minute (webhook, cron, panel): one cheap upsert per request.
// The panel reads it for "requests per minute", response times and Bale API latency.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Metrics
{
    public const SLOW_MS = 1000;

    /** Saves one finished request. Never throws: metrics must not break the bot. */
    public static function record(App $app, string $source, float $ms, bool $error = false): void
    {
        try {
            $b = $app->bale->stats;
            $minute = gmdate('Y-m-d H:i:00', $app->now());
            $app->db->run(
                'INSERT INTO {request_metrics} (minute_at, source, requests, total_ms, max_ms, slow, errors, bale_calls, bale_ms, bale_errors, db_queries, max_memory_kb)
                 VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE requests = requests + 1, total_ms = total_ms + VALUES(total_ms), max_ms = GREATEST(max_ms, VALUES(max_ms)),
                   slow = slow + VALUES(slow), errors = errors + VALUES(errors), bale_calls = bale_calls + VALUES(bale_calls),
                   bale_ms = bale_ms + VALUES(bale_ms), bale_errors = bale_errors + VALUES(bale_errors),
                   db_queries = db_queries + VALUES(db_queries), max_memory_kb = GREATEST(max_memory_kb, VALUES(max_memory_kb))',
                [
                    $minute, $source, (int) round($ms), (int) round($ms), $ms >= self::SLOW_MS ? 1 : 0, $error ? 1 : 0,
                    (int) $b['calls'], (int) round($b['ms']), (int) $b['errors'], $app->db->queries + 1, intdiv(memory_get_peak_usage(true), 1024),
                ]
            );
        } catch (\Throwable) {
            // e.g. migrate.php not run yet: the bot keeps working
        }
    }

    /**
     * Totals over the last $minutes for one source (or all when null).
     * @return array{requests:int,avg_ms:float,max_ms:int,slow:int,errors:int,bale_calls:int,bale_avg_ms:float,bale_errors:int,queries_per_req:float,max_memory_kb:int,per_min:float}
     */
    public static function summary(App $app, int $minutes, ?string $source = 'webhook'): array
    {
        $since = gmdate('Y-m-d H:i:00', $app->now() - 60 * $minutes);
        $r = $app->db->row(
            'SELECT COALESCE(SUM(requests),0) requests, COALESCE(SUM(total_ms),0) total_ms, COALESCE(MAX(max_ms),0) max_ms, COALESCE(SUM(slow),0) slow,
                    COALESCE(SUM(errors),0) errors, COALESCE(SUM(bale_calls),0) bale_calls, COALESCE(SUM(bale_ms),0) bale_ms,
                    COALESCE(SUM(bale_errors),0) bale_errors, COALESCE(SUM(db_queries),0) db_queries, COALESCE(MAX(max_memory_kb),0) max_memory_kb
             FROM {request_metrics} WHERE minute_at >= ?' . ($source ? ' AND source = ?' : ''),
            $source ? [$since, $source] : [$since]
        ) ?? [];
        $n = (int) ($r['requests'] ?? 0);
        $calls = (int) ($r['bale_calls'] ?? 0);
        return [
            'requests'        => $n,
            'avg_ms'          => $n ? $r['total_ms'] / $n : 0.0,
            'max_ms'          => (int) ($r['max_ms'] ?? 0),
            'slow'            => (int) ($r['slow'] ?? 0),
            'errors'          => (int) ($r['errors'] ?? 0),
            'bale_calls'      => $calls,
            'bale_avg_ms'     => $calls ? $r['bale_ms'] / $calls : 0.0,
            'bale_errors'     => (int) ($r['bale_errors'] ?? 0),
            'queries_per_req' => $n ? $r['db_queries'] / $n : 0.0,
            'max_memory_kb'   => (int) ($r['max_memory_kb'] ?? 0),
            'per_min'         => $minutes ? $n / $minutes : 0.0,
        ];
    }

    /** A bucket with this many times the average traffic (and at least SPIKE_MIN requests) is a spike. */
    public const SPIKE_RATIO = 3;
    public const SPIKE_MIN = 10;

    /**
     * Where a series peaks: the busiest bucket, traffic spikes, slow buckets (average response
     * of 1 s or more) and the slowest single request.
     * @return array{total:int,mean:float,avg_ms:float,peak:?int,spikes:int[],slow:int[],max_ms:int,max_at:?int}
     */
    public static function peaks(array $series): array
    {
        $total = array_sum(array_column($series, 'requests'));
        $mean = $series ? $total / count($series) : 0.0;
        $out = ['total' => $total, 'mean' => $mean, 'avg_ms' => 0.0, 'peak' => null, 'spikes' => [], 'slow' => [], 'max_ms' => 0, 'max_at' => null];
        $weighted = 0.0;
        foreach ($series as $i => $p) {
            if ($p['requests'] <= 0) {
                continue;
            }
            $weighted += $p['avg_ms'] * $p['requests'];
            if ($out['peak'] === null || $p['requests'] > $series[$out['peak']]['requests']) {
                $out['peak'] = $i;
            }
            if ($p['requests'] >= max(self::SPIKE_MIN, self::SPIKE_RATIO * $mean)) {
                $out['spikes'][] = $i;
            }
            if ($p['avg_ms'] >= self::SLOW_MS) {
                $out['slow'][] = $i;
            }
            if ($p['max_ms'] > $out['max_ms']) {
                $out['max_ms'] = $p['max_ms'];
                $out['max_at'] = $i;
            }
        }
        $out['avg_ms'] = $total ? $weighted / $total : 0.0;
        return $out;
    }

    /**
     * Time series for charts: $buckets buckets of $bucketMinutes each, oldest first.
     * @return array<int, array{t:int,requests:int,avg_ms:float,max_ms:int,bale_avg_ms:float,errors:int}>
     */
    public static function series(App $app, int $buckets, int $bucketMinutes, string $source = 'webhook'): array
    {
        $size = 60 * $bucketMinutes;
        $end = intdiv($app->now(), $size) * $size + $size;
        $start = $end - $buckets * $size;
        $rows = $app->db->all(
            'SELECT FLOOR(UNIX_TIMESTAMP(minute_at) / ?) b, SUM(requests) requests, SUM(total_ms) total_ms,
                    MAX(max_ms) max_ms, SUM(bale_calls) bale_calls, SUM(bale_ms) bale_ms, SUM(errors) errors
             FROM {request_metrics} WHERE minute_at >= ? AND source = ? GROUP BY b',
            [$size, gmdate('Y-m-d H:i:s', $start), $source]
        );
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['b']] = $r;
        }
        $out = []; // UNIX_TIMESTAMP reads minute_at as UTC: Database::connect sets the session to +00:00
        for ($i = 0; $i < $buckets; $i++) {
            $t = $start + $i * $size;
            $r = $by[intdiv($t, $size)] ?? null;
            $n = (int) ($r['requests'] ?? 0);
            $out[] = [
                't'           => $t,
                'requests'    => $n,
                'avg_ms'      => $n ? $r['total_ms'] / $n : 0.0,
                'max_ms'      => (int) ($r['max_ms'] ?? 0),
                'bale_avg_ms' => !empty($r['bale_calls']) ? $r['bale_ms'] / $r['bale_calls'] : 0.0,
                'errors'      => (int) ($r['errors'] ?? 0),
            ];
        }
        return $out;
    }
}
