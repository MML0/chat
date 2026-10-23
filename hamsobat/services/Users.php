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
     * Finds or creates the user who sent an update.
     * @return array{0:array,1:bool} [user row, true when created by this call]
     */
    public function fromBale(int $baleUserId): array
    {
        $db = $this->app->db;
        $now = $this->app->ts();
        $user = $db->row('SELECT * FROM {users} WHERE bale_user_id = ?', [$baleUserId]);
        if ($user) {
            if ($user['last_seen_at'] === null || strtotime($user['last_seen_at'] . ' UTC') < $this->app->now() - 60) {
                $db->run('UPDATE {users} SET last_seen_at = ? WHERE id = ?', [$now, $user['id']]);
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
                return [$db->row('SELECT * FROM {users} WHERE bale_user_id = ?', [$baleUserId]), true];
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
