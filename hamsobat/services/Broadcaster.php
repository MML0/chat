<?php
// "Send to all" from the admin panel. A shared host kills long requests, so a broadcast is
// a queue: each step() claims the next few users atomically (the cursor moves inside a
// row-locked transaction, so two steps never take the same users) and sends to them
// outside the transaction. Steps run while the panel page is open, on the webhook's
// periodic work and on cron/cleanup.php.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Broadcaster
{
    public const AUDIENCES = [
        'all'       => 'همه کاربران',
        'completed' => 'فقط پروفایل کامل',
        'active7'   => 'فعال در ۷ روز اخیر',
        'active30'  => 'فعال در ۳۰ روز اخیر',
        'girls'     => 'فقط دخترها',
        'boys'      => 'فقط پسرها',
        'admins'    => 'فقط ادمین‌ها (آزمایشی)',
    ];

    public function __construct(private App $app)
    {
    }

    public function available(): bool
    {
        return $this->app->db->hasTable('broadcasts');
    }

    /** SQL condition (on {users} u) + params for an audience. Suspended users never get it. */
    private function audience(string $audience): array
    {
        $now = $this->app->now();
        return match ($audience) {
            'completed' => ["u.status = 'active' AND u.profile_completed = 1", []],
            'active7'   => ["u.status = 'active' AND u.last_seen_at >= ?", [$this->app->ts($now - 7 * 86400)]],
            'active30'  => ["u.status = 'active' AND u.last_seen_at >= ?", [$this->app->ts($now - 30 * 86400)]],
            'girls'     => ["u.status = 'active' AND u.gender = 'f'", []],
            'boys'      => ["u.status = 'active' AND u.gender = 'm'", []],
            'admins'    => ['u.bale_user_id IN (' . (implode(',', array_map('intval', (array) ($this->app->config['admin']['ids'] ?? []))) ?: '0') . ')', []],
            default     => ["u.status = 'active'", []],
        };
    }

    public function count(string $audience): int
    {
        [$where, $p] = $this->audience($audience);
        return (int) $this->app->db->value("SELECT COUNT(*) FROM {users} u WHERE {$where}", $p);
    }

    /** @return int|string new broadcast id, or a Persian error */
    public function create(string $text, string $audience, string $buttonText = '', string $buttonUrl = ''): int|string
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '' || mb_strlen($text) > 3900) {
            return 'متن پیام خالی یا بیشتر از ۳۹۰۰ حرف است.';
        }
        if (!isset(self::AUDIENCES[$audience])) {
            return 'گروه گیرنده نامعتبر است.';
        }
        [$buttonText, $buttonUrl] = [trim($buttonText), trim($buttonUrl)];
        if (($buttonText === '') !== ($buttonUrl === '') || ($buttonUrl !== '' && !preg_match('~^https://\S+$~', $buttonUrl))) {
            return 'برای دکمه هم متن و هم لینک (https://…) لازم است.';
        }
        return $this->app->db->insert('broadcasts', [
            'text'        => $text,
            'button_text' => $buttonText !== '' ? mb_substr($buttonText, 0, 64) : null,
            'button_url'  => $buttonUrl !== '' ? $buttonUrl : null,
            'audience'    => $audience,
            'status'      => 'running',
            'total'       => $this->count($audience),
            'created_at'  => $this->app->ts(),
            'updated_at'  => $this->app->ts(),
        ]);
    }

    /** Sends the message to one Bale user. @return array Bale's answer */
    public function sendOne(int $baleUserId, string $text, ?string $buttonText = null, ?string $buttonUrl = null): array
    {
        $markup = $buttonText && $buttonUrl ? Ui::inline([[[$buttonText, 'url' => $buttonUrl]]]) : null;
        $res = $this->app->bale->send($baleUserId, $text, $markup);
        if (empty($res['ok']) && (int) ($res['error_code'] ?? $res['_http'] ?? 0) === 429) {
            usleep(1100000); // Bale asked to slow down: one retry
            $res = $this->app->bale->send($baleUserId, $text, $markup);
        }
        return $res;
    }

    /**
     * Sends the next batches of the oldest running broadcast for up to $seconds.
     * @return array{id:?int,sent:int,failed:int,done:bool}
     */
    public function step(float $seconds = 3.0, int $batch = 15): array
    {
        $out = ['id' => null, 'sent' => 0, 'failed' => 0, 'done' => false];
        if (!$this->available()) {
            return $out;
        }
        $until = microtime(true) + $seconds;
        $db = $this->app->db;
        while (microtime(true) < $until) {
            // Claim the next users (the cursor moves before anything is sent: no duplicates).
            $claim = $db->tx(function (Database $db) use ($batch) {
                $b = $db->row("SELECT * FROM {broadcasts} WHERE status = 'running' ORDER BY id LIMIT 1 FOR UPDATE");
                if (!$b) {
                    return null;
                }
                [$where, $p] = $this->audience($b['audience']);
                $users = $db->all("SELECT u.id, u.bale_user_id FROM {users} u WHERE {$where} AND u.id > ? ORDER BY u.id LIMIT " . (int) $batch, [...$p, (int) $b['last_user_id']]);
                if (!$users) {
                    $db->run("UPDATE {broadcasts} SET status = 'done', finished_at = ?, updated_at = ? WHERE id = ?", [$this->app->ts(), $this->app->ts(), $b['id']]);
                    return ['b' => $b, 'users' => [], 'done' => true];
                }
                $db->run('UPDATE {broadcasts} SET last_user_id = ?, updated_at = ? WHERE id = ?', [(int) end($users)['id'], $this->app->ts(), $b['id']]);
                return ['b' => $b, 'users' => $users, 'done' => false];
            });
            if (!$claim) {
                break;
            }
            $out['id'] = (int) $claim['b']['id'];
            if ($claim['done']) {
                $out['done'] = true;
                break;
            }
            [$sent, $failed] = [0, 0];
            foreach ($claim['users'] as $u) {
                $ok = !empty($this->sendOne((int) $u['bale_user_id'], $claim['b']['text'], $claim['b']['button_text'], $claim['b']['button_url'])['ok']);
                $ok ? $sent++ : $failed++;
                usleep(40000); // about 20 messages a second at most
            }
            $db->run('UPDATE {broadcasts} SET sent = sent + ?, failed = failed + ?, updated_at = ? WHERE id = ?', [$sent, $failed, $this->app->ts(), $out['id']]);
            $out['sent'] += $sent;
            $out['failed'] += $failed;
        }
        return $out;
    }

    public function setStatus(int $id, string $status): bool
    {
        $from = ['paused' => ['running'], 'running' => ['paused'], 'cancelled' => ['running', 'paused']][$status] ?? [];
        if (!$from) {
            return false;
        }
        return $this->app->db->run(
            'UPDATE {broadcasts} SET status = ?, updated_at = ?' . ($status === 'cancelled' ? ', finished_at = ?' : '') . ' WHERE id = ? AND status IN (' . implode(',', array_map(fn ($s) => "'{$s}'", $from)) . ')',
            $status === 'cancelled' ? [$status, $this->app->ts(), $this->app->ts(), $id] : [$status, $this->app->ts(), $id]
        )->rowCount() > 0;
    }

    public function recent(int $limit = 15): array
    {
        return $this->available() ? $this->app->db->all('SELECT * FROM {broadcasts} ORDER BY id DESC LIMIT ' . (int) $limit) : [];
    }

    public function running(): bool
    {
        return $this->available() && (bool) $this->app->db->value("SELECT 1 FROM {broadcasts} WHERE status = 'running' LIMIT 1");
    }
}
