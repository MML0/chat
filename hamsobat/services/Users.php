<?php
// User rows: create on first contact, conversation state, profile fields, interests.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Users
{
    /** Columns a handler may write with update(). */
    private const WRITABLE = [
        'display_name', 'nickname', 'birth_date', 'gender', 'preferred_gender', 'city', 'bio', 'photo_file_id',
        'notify_profile_views', 'show_city', 'show_bio', 'show_photo', 'profile_completed', 'inbox_code',
        'channel_verified_at', 'referral_waived',
    ];

    public function __construct(private App $app)
    {
    }

    /**
     * Finds or creates the user who sent an update. $from is Bale's "from" object: its name
     * and @username are kept in {user_accounts} for the admin (at most once a minute).
     * @return array{0:array,1:bool} [user row, true when created by this call]
     */
    public function fromBale(int $baleUserId, array $from = []): array
    {
        $db = $this->app->db;
        $now = $this->app->ts();
        $user = $db->row('SELECT * FROM {users} WHERE bale_user_id = ?', [$baleUserId]);
        if ($user) {
            if ($user['last_seen_at'] === null || strtotime($user['last_seen_at'] . ' UTC') < $this->app->now() - 60) {
                $db->run('UPDATE {users} SET last_seen_at = ? WHERE id = ?', [$now, $user['id']]);
                $this->saveAccount((int) $user['id'], $from);
            }
            return [$user, false];
        }
        for ($i = 0; $i < 5; $i++) {
            try {
                $db->insert('users', [
                    'bale_user_id'  => $baleUserId,
                    'referral_code' => Text::code(8),
                    'inbox_code'    => Text::code(10),
                    'created_at'    => $now,
                    'updated_at'    => $now,
                    'last_seen_at'  => $now,
                ]);
                $user = $db->row('SELECT * FROM {users} WHERE bale_user_id = ?', [$baleUserId]);
                $this->saveAccount((int) $user['id'], $from);
                return [$user, true];
            } catch (\PDOException $e) {
                if (!Database::isDuplicate($e)) {
                    throw $e;
                }
                // Same user in two parallel requests, or a random code collision: look again.
                $user = $db->row('SELECT * FROM {users} WHERE bale_user_id = ?', [$baleUserId]);
                if ($user) {
                    return [$user, false];
                }
            }
        }
        throw new \RuntimeException('could not create user');
    }

    // ------------------------------------------------------------------ Bale account (admin only)

    /**
     * Saves the Bale name and @username when they changed; the old ones go to previous_names.
     * Never throws: before migrate.php has created the table, it simply does nothing.
     */
    public function saveAccount(int $userId, array $from, string $source = 'update'): void
    {
        $clean = function ($v, int $max): ?string {
            $v = trim(Text::normalize((string) ($v ?? '')));
            return $v === '' ? null : mb_substr($v, 0, $max);
        };
        if (!$from) {
            return;
        }
        $username = $clean(ltrim((string) ($from['username'] ?? ''), '@'), 64);
        $first = $clean($from['first_name'] ?? null, 128);
        $last = $clean($from['last_name'] ?? null, 128);
        $db = $this->app->db;
        $now = $this->app->ts();
        try {
            if (!$db->hasTable('user_accounts')) {
                return;
            }
            $old = $db->row('SELECT * FROM {user_accounts} WHERE user_id = ?', [$userId]);
            if ($old && $old['username'] === $username && $old['first_name'] === $first && $old['last_name'] === $last) {
                return;
            }
            $previous = $old['previous_names'] ?? null;
            $oldLabel = $old ? self::accountLabel($old) : '';
            if ($oldLabel !== '' && $oldLabel !== self::accountLabel(['username' => $username, 'first_name' => $first, 'last_name' => $last])) {
                $previous = mb_substr($oldLabel . ($previous ? ' | ' . $previous : ''), 0, 1000);
            }
            $db->run(
                'INSERT INTO {user_accounts} (user_id, username, first_name, last_name, previous_names, source, first_seen_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE username = VALUES(username), first_name = VALUES(first_name), last_name = VALUES(last_name),
                   previous_names = VALUES(previous_names), source = VALUES(source), updated_at = VALUES(updated_at)',
                [$userId, $username, $first, $last, $previous, $source, $now, $now]
            );
        } catch (\PDOException $e) {
            $this->app->log->warning('saveAccount: ' . $e->getMessage());
        }
    }

    /** "@username · First Last" (either part may be missing). */
    public static function accountLabel(array $a): string
    {
        $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        return implode(' · ', array_filter([!empty($a['username']) ? '@' . $a['username'] : '', $name]));
    }

    /** Bale accounts of several users, keyed by user id. Empty before migrate.php ran. */
    public function accounts(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$ids || !$this->app->db->hasTable('user_accounts')) {
            return [];
        }
        $rows = $this->app->db->all('SELECT * FROM {user_accounts} WHERE user_id IN (' . implode(',', $ids) . ')');
        return array_column($rows, null, 'user_id');
    }

    /**
     * Users seen before v1.2 have no account row yet: ask Bale (getChat) for a few at a time.
     * A failed lookup stores an empty row so it is not asked again; the next message fills it.
     * @return int how many were looked up
     */
    public function backfillAccounts(int $limit = 30): int
    {
        $db = $this->app->db;
        if (!$db->hasTable('user_accounts')) {
            return 0;
        }
        $missing = $db->all('SELECT u.id, u.bale_user_id FROM {users} u LEFT JOIN {user_accounts} a ON a.user_id = u.id WHERE a.user_id IS NULL ORDER BY u.last_seen_at DESC LIMIT ' . (int) $limit);
        foreach ($missing as $u) {
            $res = $this->app->bale->getChat((int) $u['bale_user_id']);
            $info = !empty($res['ok']) && is_array($res['result'] ?? null) ? $res['result'] : [];
            $this->saveAccount((int) $u['id'], $info ?: ['first_name' => null], $info ? 'getChat' : 'none');
        }
        return count($missing);
    }

    public function get(int $id): ?array
    {
        return $this->app->db->row('SELECT * FROM {users} WHERE id = ?', [$id]);
    }

    public function byNickname(string $nickname): ?array
    {
        return $this->app->db->row('SELECT * FROM {users} WHERE nickname = ?', [$nickname]);
    }

    public function update(int $id, array $fields): void
    {
        if ($bad = array_diff(array_keys($fields), self::WRITABLE)) {
            throw new \InvalidArgumentException('not writable: ' . implode(',', $bad));
        }
        $set = implode(', ', array_map(fn ($c) => "{$c} = ?", array_keys($fields)));
        $this->app->db->run("UPDATE {users} SET {$set}, updated_at = ? WHERE id = ?", [...array_values($fields), $this->app->ts(), $id]);
    }

    /** True when the nickname is free (case-insensitive, thanks to the column collation). */
    public function nicknameFree(string $nickname, int $exceptId): bool
    {
        return !$this->app->db->value('SELECT id FROM {users} WHERE nickname = ? AND id <> ?', [$nickname, $exceptId]);
    }

    // ------------------------------------------------------------------ conversation state

    /** $ttl > 0 makes the state expire (e.g. "write your anonymous message" after 15 minutes). */
    public function setState(int $id, string $state, array $data = [], int $ttl = 0): void
    {
        if ($ttl > 0) {
            $data['exp'] = $this->app->now() + $ttl;
        }
        $this->app->db->run('UPDATE {users} SET state = ?, state_data = ? WHERE id = ?', [$state, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, $id]);
    }

    public function clearState(int $id): void
    {
        $this->setState($id, '');
    }

    /** @return array{0:string,1:array} [state, data]; an expired state reads as ''. */
    public function state(array $user): array
    {
        $state = (string) $user['state'];
        $data = $user['state_data'] ? (json_decode($user['state_data'], true) ?: []) : [];
        if ($state !== '' && isset($data['exp']) && $data['exp'] < $this->app->now()) {
            $this->clearState((int) $user['id']);
            return ['', []];
        }
        return [$state, $data];
    }

    // ------------------------------------------------------------------ profile

    public function interests(int $id): array
    {
        return array_column($this->app->db->all('SELECT interest FROM {user_interests} WHERE user_id = ? ORDER BY id', [$id]), 'interest');
    }

    public function setInterests(int $id, array $interests): void
    {
        $this->app->db->tx(function (Database $db) use ($id, $interests) {
            $db->run('DELETE FROM {user_interests} WHERE user_id = ?', [$id]);
            foreach ($interests as $i) {
                $db->run('INSERT IGNORE INTO {user_interests} (user_id, interest) VALUES (?, ?)', [$id, $i]);
            }
        });
    }

    public function isSuspended(array $user): bool
    {
        if ($user['status'] !== 'suspended') {
            return false;
        }
        if ($user['suspended_until'] !== null && strtotime($user['suspended_until'] . ' UTC') <= $this->app->now()) {
            $this->app->db->run("UPDATE {users} SET status = 'active', suspended_until = NULL WHERE id = ?", [$user['id']]);
            return false;
        }
        return true;
    }

    /** Missing required profile fields, in the order they are asked. */
    public static function missing(array $u): array
    {
        $required = ['name' => 'display_name', 'nick' => 'nickname', 'birth' => 'birth_date', 'gender' => 'gender', 'pref' => 'preferred_gender'];
        return array_keys(array_filter($required, fn ($col) => empty($u[$col])));
    }
}
