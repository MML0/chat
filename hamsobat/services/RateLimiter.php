<?php
// Fixed-window counters per user and bucket (messages, searches, inbox, reports).
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class RateLimiter
{
    public function __construct(private App $app)
    {
    }

    /** Counts one hit and returns the number of hits in the current window (1 = first). */
    public function hit(int $userId, string $bucket, int $window): int
    {
        $start = intdiv($this->app->now(), $window) * $window;
        $db = $this->app->db;
        // hits is updated before window_start, so it still sees the old window.
        $db->run(
            'INSERT INTO {rate_limits} (user_id, bucket, window_start, hits) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = IF(window_start = VALUES(window_start), hits + 1, 1), window_start = VALUES(window_start)',
            [$userId, $bucket, $start]
        );
        return (int) $db->value('SELECT hits FROM {rate_limits} WHERE user_id = ? AND bucket = ?', [$userId, $bucket]);
    }

    /** True while within $limit hits per $window seconds. $limit <= 0 means no limit. */
    public function allow(int $userId, string $bucket, int $limit, int $window): bool
    {
        return $limit <= 0 || $this->hit($userId, $bucket, $window) <= $limit;
    }
}
