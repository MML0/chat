<?php
// Periodic work. The host may have no cron, so webhook.php calls tick() on every update
// (it does real work at most every 10 s) and cron/cleanup.php can call run() from a real
// cron or an external "visit this URL every minute" service for exact search timeouts.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Maintenance
{
    public function __construct(private App $app)
    {
    }

    /** Cheap: search timeouts + idle chats every 10 s, cleanup once an hour. */
    public function tick(): void
    {
        $now = $this->app->now();
        if ($this->app->settings->claim('_tick', 10, $now)) {
            $this->expireSearches();
            $this->endIdleChats();
        }
        if ($this->app->settings->claim('_purge', 3600, $now)) {
            $this->purge();
        }
    }

    /**
     * After the webhook has done its work: send the next batch of a running broadcast
     * (at most every 5 s, at most 2 s). Bale's connection is released first when PHP-FPM allows.
     */
    public function afterResponse(): void
    {
        if (!$this->app->broadcaster()->running() || !$this->app->settings->claim('_bc_tick', 5, $this->app->now())) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $this->app->broadcaster()->step(2.0, 15);
    }

    /** Everything, now (cron). @return array counts for the cron output */
    public function run(): array
    {
        return [
            'broadcast'        => $this->app->broadcaster()->running() ? $this->app->broadcaster()->step(20.0, 25)['sent'] : 0,
            'expired_searches' => $this->expireSearches(),
            'idle_chats'       => $this->endIdleChats(),
            'purged'           => $this->purge(),
        ];
    }

    public function expireSearches(): int
    {
        $ids = $this->app->matching()->expireSearches();
        foreach ($ids as $id) {
            $u = $this->app->users()->get($id);
            if ($u) {
                $this->app->bale->send((int) $u['bale_user_id'], "😕 فعلاً هم‌صحبتی پیدا نشد.\nچند لحظه دیگه دوباره امتحان کن؛ از این جست‌وجو چیزی از سهمیه‌ات کم نشد.", Ui::mainKeyboard());
            }
        }
        return count($ids);
    }

    public function endIdleChats(): int
    {
        $n = 0;
        foreach ($this->app->matching()->idleChats($this->app->settings->int('chat_idle_minutes')) as $chatId) {
            foreach ($this->app->matching()->endById($chatId, 'idle') as $userId) {
                $u = $this->app->users()->get($userId);
                if ($u) {
                    $this->app->bale->send((int) $u['bale_user_id'], '⏱ چت به‌خاطر بی‌فعالیتی تموم شد.', Ui::mainKeyboard());
                }
            }
            $n++;
        }
        return $n;
    }

    /** Deletes what is no longer needed. Messages of chats with an open report are kept. */
    public function purge(): array
    {
        $db = $this->app->db;
        $now = $this->app->now();
        $keepDays = max(1, $this->app->settings->int('message_retention_days'));
        return [
            'updates'  => $db->run('DELETE FROM {updates_seen} WHERE seen_at < ?', [$this->app->ts($now - 2 * 86400)])->rowCount(),
            'rates'    => $db->run('DELETE FROM {rate_limits} WHERE window_start < ?', [$now - 2 * 86400])->rowCount(),
            'messages' => $db->run(
                "DELETE FROM {messages} WHERE created_at < ? AND chat_id NOT IN (SELECT chat_id FROM {reports} WHERE status = 'open' AND chat_id IS NOT NULL)",
                [$this->app->ts($now - $keepDays * 86400)]
            )->rowCount(),
            'errors'   => $db->run('DELETE FROM {error_logs} WHERE created_at < ?', [$this->app->ts($now - 30 * 86400)])->rowCount(),
            'views'    => $db->run('DELETE FROM {profile_views} WHERE viewed_at < ?', [$this->app->ts($now - 90 * 86400)])->rowCount(),
            'metrics'  => $this->quiet(fn () => $db->run('DELETE FROM {request_metrics} WHERE minute_at < ?', [$this->app->ts($now - 14 * 86400)])->rowCount()),
            'audit'    => $this->quiet(fn () => $db->run('DELETE FROM {admin_log} WHERE created_at < ?', [$this->app->ts($now - 180 * 86400)])->rowCount()),
            'media'    => $this->purgeMediaCache($now - 7 * 86400),
            'accounts' => $this->quiet(fn () => $this->app->users()->backfillAccounts(30)),
            'referrals' => $this->retryReferrals(),
        ];
    }

    /** Friends who finished their profile but were not counted yet (e.g. joined the channel later). */
    private function retryReferrals(): int
    {
        $n = 0;
        foreach ($this->app->referrals()->pendingToRetry(30) as $invited) {
            $this->app->handler(ReferralHandler::class)->qualify($invited);
            $n++;
        }
        return $n;
    }

    /** Tables added in v1.1 may not exist until migrate.php runs. */
    private function quiet(callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The panel keeps Bale files for 7 days in storage/cache/media. */
    private function purgeMediaCache(int $before): int
    {
        $n = 0;
        foreach (glob(dirname(__DIR__) . '/storage/cache/media/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $before && @unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /** Numbers for /stats and the daily admin report. */
    public function stats(): array
    {
        $db = $this->app->db;
        $tz = new \DateTimeZone($this->app->config['timezone'] ?? 'Asia/Tehran');
        $dayStart = $this->app->ts((new \DateTimeImmutable($this->app->today() . ' 00:00:00', $tz))->getTimestamp());
        $now = $this->app->ts();
        return [
            'users'         => (int) $db->value('SELECT COUNT(*) FROM {users}'),
            'completed'     => (int) $db->value('SELECT COUNT(*) FROM {users} WHERE profile_completed = 1'),
            'new_today'     => (int) $db->value('SELECT COUNT(*) FROM {users} WHERE created_at >= ?', [$dayStart]),
            'active_today'  => (int) $db->value('SELECT COUNT(*) FROM {users} WHERE last_seen_at >= ?', [$dayStart]),
            'searching'     => (int) $db->value("SELECT COUNT(*) FROM {chat_queue} WHERE status = 'waiting' AND expires_at > ?", [$now]),
            'chatting'      => (int) $db->value('SELECT COUNT(*) FROM {active_chats}'),
            'chats_today'   => (int) $db->value('SELECT COUNT(*) FROM {chats} WHERE started_at >= ?', [$dayStart]),
            'messages_today' => (int) $db->value("SELECT COUNT(*) FROM {messages} WHERE created_at >= ? AND delivery_status = 'sent'", [$dayStart]),
            'referrals_today' => (int) $db->value("SELECT COUNT(*) FROM {referrals} WHERE status = 'qualified' AND qualified_at >= ?", [$dayStart]),
            'inbox_today'   => (int) $db->value('SELECT COUNT(*) FROM {anonymous_inbox} WHERE created_at >= ?', [$dayStart]),
            'open_reports'  => (int) $db->value("SELECT COUNT(*) FROM {reports} WHERE status = 'open'"),
            'suspended'     => (int) $db->value("SELECT COUNT(*) FROM {users} WHERE status = 'suspended'"),
            'errors_today'  => (int) $db->value('SELECT COUNT(*) FROM {error_logs} WHERE created_at >= ?', [$dayStart]),
        ];
    }

    public static function statsText(array $s): string
    {
        $f = fn ($n) => Text::fa($n);
        return "📊 آمار هم‌صحبت\n\n"
            . "👥 کاربران: {$f($s['users'])} (پروفایل کامل: {$f($s['completed'])})\n"
            . "🆕 جدید امروز: {$f($s['new_today'])} | فعال امروز: {$f($s['active_today'])}\n"
            . "🔎 در حال جست‌وجو: {$f($s['searching'])} | 💬 در حال چت: {$f($s['chatting'])}\n"
            . "🤝 چت‌های امروز: {$f($s['chats_today'])} | ✉️ پیام‌ها: {$f($s['messages_today'])}\n"
            . "🎁 دعوت موفق امروز: {$f($s['referrals_today'])} | 📥 پیام ناشناس امروز: {$f($s['inbox_today'])}\n"
            . "🚩 گزارش باز: {$f($s['open_reports'])} | ⛔ معلق: {$f($s['suspended'])} | ⚠️ خطای امروز: {$f($s['errors_today'])}";
    }
}
