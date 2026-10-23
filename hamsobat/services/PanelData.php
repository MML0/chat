<?php
// Every query the admin panel needs. Days and hours are in the app timezone (Tehran),
// the database stores UTC.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class PanelData
{
    public const PER_PAGE = 30;
    public const END_REASONS = [
        'end' => 'پایان توسط کاربر', 'next' => 'نفر بعدی', 'block' => 'مسدود شد', 'idle' => 'بی‌فعالیتی',
        'unreachable' => 'خروج از ربات', 'suspended' => 'تعلیق', 'left' => 'ربات را بست', 'admin' => 'پایان توسط مدیر',
    ];
    private const USER_COLS = 'u.id, u.bale_user_id, u.nickname, u.display_name, u.gender, u.preferred_gender, u.birth_date, u.city, u.photo_file_id, u.status, u.profile_completed, u.referral_waived, u.created_at, u.last_seen_at';

    private Database $db;

    public function __construct(private App $app)
    {
        $this->db = $app->db;
    }

    /** Seconds the app timezone is ahead of UTC (Tehran: 12600). */
    public function offset(): int
    {
        return (new \DateTimeZone($this->app->config['timezone'] ?? 'Asia/Tehran'))->getOffset(new \DateTimeImmutable('@' . $this->app->now()));
    }

    /** UTC DATETIME of local midnight, $daysAgo days back. */
    public function dayStart(int $daysAgo = 0): string
    {
        $tz = new \DateTimeZone($this->app->config['timezone'] ?? 'Asia/Tehran');
        $d = (new \DateTimeImmutable('@' . $this->app->now()))->setTimezone($tz)->setTime(0, 0)->modify("-{$daysAgo} days");
        return gmdate('Y-m-d H:i:s', $d->getTimestamp());
    }

    private function ago(int $seconds): string
    {
        return $this->app->ts($this->app->now() - $seconds);
    }

    // ------------------------------------------------------------------ dashboard

    public function kpis(): array
    {
        $db = $this->db;
        $today = $this->dayStart();
        $yesterday = $this->dayStart(1);
        $now = $this->app->ts();
        $c = fn (string $sql, array $p = []) => (int) $db->value($sql, $p);
        return [
            'users'           => $c('SELECT COUNT(*) FROM {users}'),
            'completed'       => $c('SELECT COUNT(*) FROM {users} WHERE profile_completed = 1'),
            'new_today'       => $c('SELECT COUNT(*) FROM {users} WHERE created_at >= ?', [$today]),
            'new_yesterday'   => $c('SELECT COUNT(*) FROM {users} WHERE created_at >= ? AND created_at < ?', [$yesterday, $today]),
            'online'          => $c('SELECT COUNT(*) FROM {users} WHERE last_seen_at >= ?', [$this->ago(300)]),
            'active_today'    => $c('SELECT COUNT(*) FROM {users} WHERE last_seen_at >= ?', [$today]),
            'searching'       => $c("SELECT COUNT(*) FROM {chat_queue} WHERE status = 'waiting' AND expires_at > ?", [$now]),
            'chatting'        => intdiv($c('SELECT COUNT(*) FROM {active_chats}'), 2),
            'chats_today'     => $c('SELECT COUNT(*) FROM {chats} WHERE started_at >= ?', [$today]),
            'chats_yesterday' => $c('SELECT COUNT(*) FROM {chats} WHERE started_at >= ? AND started_at < ?', [$yesterday, $today]),
            'messages_today'  => $c("SELECT COUNT(*) FROM {messages} WHERE created_at >= ? AND delivery_status = 'sent'", [$today]),
            'filtered_today'  => $c("SELECT COUNT(*) FROM {messages} WHERE created_at >= ? AND delivery_status = 'filtered'", [$today]),
            'referrals_today' => $c("SELECT COUNT(*) FROM {referrals} WHERE status = 'qualified' AND qualified_at >= ?", [$today]),
            'inbox_today'     => $c('SELECT COUNT(*) FROM {anonymous_inbox} WHERE created_at >= ?', [$today]),
            'open_reports'    => $c("SELECT COUNT(*) FROM {reports} WHERE status = 'open'"),
            'suspended'       => $c("SELECT COUNT(*) FROM {users} WHERE status = 'suspended'"),
            'errors_24h'      => $c('SELECT COUNT(*) FROM {error_logs} WHERE created_at >= ?', [$this->ago(86400)]),
            'avg_chat_sec'    => (int) $db->value("SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, ended_at)) FROM {chats} WHERE ended_at IS NOT NULL AND started_at >= ?", [$this->ago(7 * 86400)]),
            'avg_chat_msgs'   => (float) $db->value('SELECT AVG(message_count) FROM {chats} WHERE started_at >= ?', [$this->ago(7 * 86400)]),
        ];
    }

    /** Per local day for the last $days days: users, chats, messages, referrals. Oldest first. */
    public function daily(int $days): array
    {
        $off = $this->offset();
        $since = $this->dayStart($days - 1);
        $series = [];
        foreach (['users' => 'SELECT DATE(created_at + INTERVAL %d SECOND) d, COUNT(*) n FROM {users} WHERE created_at >= ? GROUP BY d',
                  'chats' => 'SELECT DATE(started_at + INTERVAL %d SECOND) d, COUNT(*) n FROM {chats} WHERE started_at >= ? GROUP BY d',
                  'messages' => "SELECT DATE(created_at + INTERVAL %d SECOND) d, COUNT(*) n FROM {messages} WHERE created_at >= ? AND delivery_status = 'sent' GROUP BY d",
                  'referrals' => "SELECT DATE(qualified_at + INTERVAL %d SECOND) d, COUNT(*) n FROM {referrals} WHERE status = 'qualified' AND qualified_at >= ? GROUP BY d",
                  ] as $key => $sql) {
            foreach ($this->db->all(sprintf($sql, $off), [$since]) as $r) {
                $series[$key][$r['d']] = (int) $r['n'];
            }
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $this->app->today($this->app->now() - $i * 86400);
            $out[] = ['date' => $d, 'users' => $series['users'][$d] ?? 0, 'chats' => $series['chats'][$d] ?? 0, 'messages' => $series['messages'][$d] ?? 0, 'referrals' => $series['referrals'][$d] ?? 0];
        }
        return $out;
    }

    /** Messages by weekday (0 = Saturday) × local hour over the last $days days. */
    public function heatmap(int $days): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $off = $this->offset();
        $rows = $this->db->all(
            "SELECT DAYOFWEEK(created_at + INTERVAL {$off} SECOND) dw, HOUR(created_at + INTERVAL {$off} SECOND) h, COUNT(*) n
             FROM {messages} WHERE created_at >= ? AND delivery_status = 'sent' GROUP BY dw, h",
            [$this->ago($days * 86400)]
        );
        foreach ($rows as $r) {
            $grid[((int) $r['dw']) % 7][(int) $r['h']] = (int) $r['n']; // MySQL: 1 = Sunday … 7 = Saturday
        }
        return $grid;
    }

    public function demographics(): array
    {
        $db = $this->db;
        $genders = ['m' => 0, 'f' => 0];
        foreach ($db->all('SELECT gender, COUNT(*) n FROM {users} WHERE profile_completed = 1 GROUP BY gender') as $r) {
            if (isset($genders[$r['gender']])) {
                $genders[$r['gender']] = (int) $r['n'];
            }
        }
        $buckets = ['۱۳–۱۷' => [13, 17], '۱۸–۲۱' => [18, 21], '۲۲–۲۵' => [22, 25], '۲۶–۳۰' => [26, 30], '۳۱–۳۵' => [31, 35], '۳۶–۴۵' => [36, 45], '۴۶+' => [46, 200]];
        $ages = array_fill_keys(array_keys($buckets), 0);
        $today = $this->app->today();
        foreach ($db->all('SELECT birth_date FROM {users} WHERE profile_completed = 1 AND birth_date IS NOT NULL') as $r) {
            $a = Text::age($r['birth_date'], $today);
            foreach ($buckets as $k => [$lo, $hi]) {
                if ($a >= $lo && $a <= $hi) {
                    $ages[$k]++;
                }
            }
        }
        return [
            'genders'   => $genders,
            'ages'      => $ages,
            'cities'    => $db->all('SELECT city label, COUNT(*) value FROM {users} WHERE city IS NOT NULL GROUP BY city ORDER BY value DESC LIMIT 8'),
            'interests' => $db->all('SELECT interest label, COUNT(*) value FROM {user_interests} GROUP BY interest ORDER BY value DESC LIMIT 8'),
            'prefs'     => $db->all('SELECT preferred_gender label, COUNT(*) value FROM {users} WHERE profile_completed = 1 GROUP BY preferred_gender'),
        ];
    }

    /** Most active users: chats in the last $days days, plus stored messages and invites. */
    public function topUsers(int $days = 30, int $limit = 10): array
    {
        $since = $this->ago($days * 86400);
        return $this->db->all(
            'SELECT ' . self::USER_COLS . ', x.chats,
                    (SELECT COUNT(*) FROM {messages} m WHERE m.sender_user_id = u.id AND m.created_at >= ?) messages,
                    (SELECT COUNT(*) FROM {referrals} r WHERE r.inviter_user_id = u.id AND r.status = \'qualified\') invites
             FROM (SELECT uid, COUNT(*) chats FROM (
                       SELECT user_one_id uid FROM {chats} WHERE started_at >= ?
                       UNION ALL SELECT user_two_id uid FROM {chats} WHERE started_at >= ?) a GROUP BY uid) x
             JOIN {users} u ON u.id = x.uid
             ORDER BY x.chats DESC, messages DESC LIMIT ' . (int) $limit,
            [$since, $since, $since]
        );
    }

    public function topInviters(int $limit = 8): array
    {
        return $this->db->all(
            'SELECT ' . self::USER_COLS . ", COUNT(*) invites FROM {referrals} r JOIN {users} u ON u.id = r.inviter_user_id
             WHERE r.status = 'qualified' GROUP BY u.id ORDER BY invites DESC LIMIT " . (int) $limit
        );
    }

    public function live(): array
    {
        $now = $this->app->ts();
        $last = $this->db->value("SELECT MAX(minute_at) FROM {request_metrics} WHERE source = 'webhook'");
        return [
            'online'    => (int) $this->db->value('SELECT COUNT(*) FROM {users} WHERE last_seen_at >= ?', [$this->ago(300)]),
            'searching' => (int) $this->db->value("SELECT COUNT(*) FROM {chat_queue} WHERE status = 'waiting' AND expires_at > ?", [$now]),
            'chatting'  => intdiv((int) $this->db->value('SELECT COUNT(*) FROM {active_chats}'), 2),
            'last_hook' => $last,
            'req_min'   => (int) $this->db->value("SELECT COALESCE(SUM(requests), 0) FROM {request_metrics} WHERE source = 'webhook' AND minute_at >= ?", [gmdate('Y-m-d H:i:00', $this->app->now() - 300)]),
        ];
    }

    // ------------------------------------------------------------------ users

    /** @return array{0:array,1:int} rows, total */
    public function users(array $f, int $page): array
    {
        $where = ['1 = 1'];
        $p = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $plain = ltrim(Text::digits($q), '#@');
            if (ctype_digit($plain)) {
                $where[] = '(u.id = ? OR u.bale_user_id = ?)';
                array_push($p, (int) $plain, (int) $plain);
            } else {
                $where[] = '(u.nickname LIKE ? OR u.display_name LIKE ? OR u.city LIKE ?)';
                $like = '%' . addcslashes($plain, '%_\\') . '%';
                array_push($p, $like, $like, $like);
            }
        }
        switch ($f['status'] ?? '') {
            case 'suspended':
                $where[] = "u.status = 'suspended'";
                break;
            case 'incomplete':
                $where[] = 'u.profile_completed = 0';
                break;
            case 'online':
                $where[] = 'u.last_seen_at >= ?';
                $p[] = $this->ago(300);
                break;
            case 'unlocked':
                $where[] = 'u.referral_waived = 1';
                break;
            case 'unlimited':
                $where[] = 'EXISTS (SELECT 1 FROM {user_perks} k WHERE k.user_id = u.id AND k.unlimited = 1 AND (k.unlimited_until IS NULL OR k.unlimited_until > ?))';
                $p[] = $this->app->ts();
                break;
        }
        if (in_array($f['gender'] ?? '', ['m', 'f'], true)) {
            $where[] = 'u.gender = ?';
            $p[] = $f['gender'];
        }
        $order = match ($f['sort'] ?? '') {
            'seen'     => 'u.last_seen_at DESC',
            'chats'    => 'chats DESC, u.id DESC',
            'messages' => 'messages DESC, u.id DESC',
            'invites'  => 'invites DESC, u.id DESC',
            'credits'  => 'credits DESC, u.id DESC',
            'old'      => 'u.id ASC',
            default    => 'u.id DESC',
        };
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM {users} u WHERE {$w}", $p);
        $rows = $this->db->all(
            'SELECT ' . self::USER_COLS . ",
                (SELECT COUNT(*) FROM {chats} c WHERE c.user_one_id = u.id) + (SELECT COUNT(*) FROM {chats} c WHERE c.user_two_id = u.id) chats,
                (SELECT COUNT(*) FROM {messages} m WHERE m.sender_user_id = u.id) messages,
                (SELECT COUNT(*) FROM {referrals} r WHERE r.inviter_user_id = u.id AND r.status = 'qualified') invites,
                COALESCE((SELECT credit_balance FROM {user_credits} k WHERE k.user_id = u.id), 0) credits,
                (SELECT chat_id FROM {active_chats} a WHERE a.user_id = u.id) active_chat
             FROM {users} u WHERE {$w} ORDER BY {$order} LIMIT " . self::PER_PAGE . ' OFFSET ' . max(0, ($page - 1) * self::PER_PAGE),
            $p
        );
        return [$rows, $total];
    }

    public function user(int $id): ?array
    {
        $u = $this->db->row('SELECT * FROM {users} WHERE id = ?', [$id]);
        if (!$u) {
            return null;
        }
        $db = $this->db;
        $u['interests'] = $this->app->users()->interests($id);
        $u['quota'] = $this->app->quota()->status($id);
        $u['active_chat'] = $this->app->matching()->activeChat($id);
        $u['searching'] = $this->app->matching()->isSearching($id);
        $u['inviter'] = $u['referred_by_user_id'] ? $db->row('SELECT ' . self::USER_COLS . ' FROM {users} u WHERE u.id = ?', [$u['referred_by_user_id']]) : null;
        $u['invited'] = $db->all('SELECT ' . self::USER_COLS . ', r.status ref_status, r.reward_credits, r.created_at ref_at FROM {referrals} r JOIN {users} u ON u.id = r.invited_user_id WHERE r.inviter_user_id = ? ORDER BY r.id DESC LIMIT 50', [$id]);
        $u['ledger'] = $db->all('SELECT * FROM {credit_ledger} WHERE user_id = ? AND delta <> 0 ORDER BY id DESC LIMIT 30', [$id]);
        $u['chats'] = $this->chats(['user' => $id], 1, 50)[0];
        $u['reports_against'] = $db->all('SELECT r.*, a.nickname reporter FROM {reports} r JOIN {users} a ON a.id = r.reporter_user_id WHERE r.reported_user_id = ? ORDER BY r.id DESC LIMIT 20', [$id]);
        $u['reports_by'] = (int) $db->value('SELECT COUNT(*) FROM {reports} WHERE reporter_user_id = ?', [$id]);
        $u['blocked'] = (int) $db->value('SELECT COUNT(*) FROM {blocked_users} WHERE blocker_user_id = ?', [$id]);
        $u['blocked_by'] = (int) $db->value('SELECT COUNT(*) FROM {blocked_users} WHERE blocked_user_id = ?', [$id]);
        $u['inbox_in'] = (int) $db->value('SELECT COUNT(*) FROM {anonymous_inbox} WHERE recipient_user_id = ?', [$id]);
        $u['inbox_out'] = (int) $db->value('SELECT COUNT(*) FROM {anonymous_inbox} WHERE sender_user_id = ?', [$id]);
        $u['messages'] = (int) $db->value("SELECT COUNT(*) FROM {messages} WHERE sender_user_id = ? AND delivery_status = 'sent'", [$id]);
        $u['views'] = (int) $db->value('SELECT COUNT(*) FROM {profile_views} WHERE profile_owner_id = ?', [$id]);
        $u['perk'] = $this->app->quota()->unlimited($id);
        $u['activity'] = array_map('intval', array_column($db->all(
            'SELECT DATE(created_at + INTERVAL ' . $this->offset() . " SECOND) d, COUNT(*) n FROM {messages} WHERE sender_user_id = ? AND created_at >= ? GROUP BY d ORDER BY d",
            [$id, $this->dayStart(13)]
        ), 'n', 'd'));
        return $u;
    }

    // ------------------------------------------------------------------ chats

    /** @return array{0:array,1:int} */
    public function chats(array $f, int $page, int $per = self::PER_PAGE): array
    {
        $where = ['1 = 1'];
        $p = [];
        if (!empty($f['user'])) {
            $where[] = '(c.user_one_id = ? OR c.user_two_id = ?)';
            array_push($p, (int) $f['user'], (int) $f['user']);
        }
        if (($f['status'] ?? '') === 'active') {
            $where[] = "c.status = 'active'";
        } elseif (($f['status'] ?? '') === 'ended') {
            $where[] = "c.status = 'ended'";
        }
        if (!empty($f['reported'])) {
            $where[] = 'EXISTS (SELECT 1 FROM {reports} r WHERE r.chat_id = c.id)';
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $where[] = '(a.nickname LIKE ? OR b.nickname LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $like, $like);
        }
        $w = implode(' AND ', $where);
        $join = 'FROM {chats} c JOIN {users} a ON a.id = c.user_one_id JOIN {users} b ON b.id = c.user_two_id';
        $total = (int) $this->db->value("SELECT COUNT(*) {$join} WHERE {$w}", $p);
        $rows = $this->db->all(
            "SELECT c.*, a.nickname a_nick, a.photo_file_id a_photo, a.gender a_gender, b.nickname b_nick, b.photo_file_id b_photo, b.gender b_gender,
                    (SELECT COUNT(*) FROM {reports} r WHERE r.chat_id = c.id) reports,
                    (SELECT m.text_content FROM {messages} m WHERE m.chat_id = c.id AND m.delivery_status = 'sent' ORDER BY m.id DESC LIMIT 1) last_text,
                    (SELECT m.message_type FROM {messages} m WHERE m.chat_id = c.id AND m.delivery_status = 'sent' ORDER BY m.id DESC LIMIT 1) last_type
             {$join} WHERE {$w} ORDER BY c.id DESC LIMIT " . (int) $per . ' OFFSET ' . max(0, ($page - 1) * $per),
            $p
        );
        return [$rows, $total];
    }

    public function chat(int $id): ?array
    {
        $c = $this->db->row('SELECT * FROM {chats} WHERE id = ?', [$id]);
        if (!$c) {
            return null;
        }
        $c['one'] = $this->db->row('SELECT * FROM {users} WHERE id = ?', [$c['user_one_id']]);
        $c['two'] = $this->db->row('SELECT * FROM {users} WHERE id = ?', [$c['user_two_id']]);
        $c['reports'] = $this->db->all('SELECT r.*, a.nickname reporter FROM {reports} r JOIN {users} a ON a.id = r.reporter_user_id WHERE r.chat_id = ? ORDER BY r.id', [$id]);
        $c['stored'] = (int) $this->db->value('SELECT COUNT(*) FROM {messages} WHERE chat_id = ?', [$id]);
        return $c;
    }

    public function messages(int $chatId, int $afterId = 0, int $limit = 500): array
    {
        return $this->db->all('SELECT * FROM {messages} WHERE chat_id = ? AND id > ? ORDER BY id LIMIT ' . (int) $limit, [$chatId, $afterId]);
    }

    /** The live stream: newest relayed messages with both nicknames. */
    public function feed(int $afterId, int $limit = 40): array
    {
        $rows = $this->db->all(
            "SELECT m.id, m.chat_id, m.message_type, m.text_content, m.delivery_status, m.created_at, m.bale_file_id IS NOT NULL has_file,
                    s.id s_id, s.nickname s_nick, s.gender s_gender, r.id r_id, r.nickname r_nick, r.gender r_gender
             FROM {messages} m
             JOIN {chats} c ON c.id = m.chat_id
             JOIN {users} s ON s.id = m.sender_user_id
             JOIN {users} r ON r.id = IF(c.user_one_id = m.sender_user_id, c.user_two_id, c.user_one_id)
             WHERE m.id > ? ORDER BY m.id DESC LIMIT " . (int) $limit,
            [$afterId]
        );
        return array_reverse($rows);
    }

    // ------------------------------------------------------------------ inbox, reports, logs

    public function inbox(int $page): array
    {
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {anonymous_inbox}');
        $rows = $this->db->all(
            'SELECT i.*, s.nickname s_nick, s.photo_file_id s_photo, r.nickname r_nick, r.photo_file_id r_photo,
                    (SELECT COUNT(*) FROM {anonymous_inbox} t WHERE t.session_id = i.session_id) thread
             FROM {anonymous_inbox} i JOIN {users} s ON s.id = i.sender_user_id JOIN {users} r ON r.id = i.recipient_user_id
             ORDER BY i.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . max(0, ($page - 1) * self::PER_PAGE)
        );
        return [$rows, $total];
    }

    public function thread(int $sessionId): ?array
    {
        $s = $this->db->row('SELECT * FROM {anonymous_sessions} WHERE id = ?', [$sessionId]);
        if (!$s) {
            return null;
        }
        $s['owner'] = $this->db->row('SELECT * FROM {users} WHERE id = ?', [$s['owner_user_id']]);
        $s['visitor'] = $this->db->row('SELECT * FROM {users} WHERE id = ?', [$s['visitor_user_id']]);
        $s['messages'] = $this->db->all('SELECT * FROM {anonymous_inbox} WHERE session_id = ? ORDER BY id', [$sessionId]);
        return $s;
    }

    public function reports(string $status, int $page): array
    {
        $status = in_array($status, ['open', 'resolved', 'dismissed'], true) ? $status : 'open';
        $total = (int) $this->db->value('SELECT COUNT(*) FROM {reports} WHERE status = ?', [$status]);
        $rows = $this->db->all(
            "SELECT r.*, a.nickname a_nick, a.photo_file_id a_photo, b.nickname b_nick, b.photo_file_id b_photo, b.status b_status,
                    (SELECT COUNT(*) FROM {reports} x WHERE x.reported_user_id = r.reported_user_id) b_reports,
                    (SELECT COUNT(DISTINCT x.reporter_user_id) FROM {reports} x WHERE x.reported_user_id = r.reported_user_id) b_reporters
             FROM {reports} r JOIN {users} a ON a.id = r.reporter_user_id JOIN {users} b ON b.id = r.reported_user_id
             WHERE r.status = ? ORDER BY r.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . max(0, ($page - 1) * self::PER_PAGE),
            [$status]
        );
        foreach ($rows as &$r) {
            $r['excerpt'] = $r['chat_id']
                ? $this->db->all('SELECT sender_user_id, message_type, text_content FROM {messages} WHERE chat_id = ? ORDER BY id DESC LIMIT 4', [$r['chat_id']])
                : $this->db->all('SELECT sender_user_id, message_type, text_content FROM {anonymous_inbox} WHERE id = ?', [(int) $r['inbox_message_id']]);
            $r['excerpt'] = array_reverse($r['excerpt']);
        }
        return [$rows, $total];
    }

    public function errors(int $limit = 50, string $level = ''): array
    {
        return $level !== ''
            ? $this->db->all('SELECT * FROM {error_logs} WHERE level = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$level])
            : $this->db->all('SELECT * FROM {error_logs} ORDER BY id DESC LIMIT ' . (int) $limit);
    }

    public function audit(int $limit = 50): array
    {
        try {
            return $this->db->all('SELECT * FROM {admin_log} ORDER BY id DESC LIMIT ' . (int) $limit);
        } catch (\Throwable) {
            return [];
        }
    }
}
