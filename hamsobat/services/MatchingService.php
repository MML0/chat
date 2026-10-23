<?php
// Queue and matching. Everything that changes the queue or who is chatting runs in a
// transaction that first locks the 'matching' row in {locks} (SELECT … FOR UPDATE).
// That serialises matchers, so two webhooks can never take the same waiting user, and
// two people who press "search" at the same moment are matched instead of both waiting.
// The PRIMARY KEY of {active_chats} makes "one active chat per user" a database rule too.
// No Bale calls happen inside these transactions; handlers notify users after commit.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class MatchingService
{
    public function __construct(private App $app)
    {
    }

    /**
     * Matches $user with the longest-waiting compatible user, or puts them in the queue.
     * The caller has already checked the profile, channel and referral requirements.
     *
     * @return array{status:string,chat_id?:int,partner_id?:int,dropped?:int[]}
     *   status: matched | queued | renewed | in_chat | no_credits | incomplete
     */
    public function search(array $user): array
    {
        $me = (int) $user['id'];
        if (Users::missing($user)) {
            return ['status' => 'incomplete'];
        }
        $s = $this->app->settings;
        $now = $this->app->now();
        $today = $this->app->today($now);
        $adultCutoff = date('Y-m-d', strtotime("{$today} -18 years"));
        $p = [
            'now'     => $this->app->ts($now),
            'me'      => $me,
            'pref1'   => $user['preferred_gender'],
            'pref2'   => $user['preferred_gender'],
            'gender'  => $user['gender'],
            'cutoff'  => $adultCutoff,
            'adult'   => $user['birth_date'] <= $adultCutoff ? 1 : 0,
            'me2'     => $me,
            'me3'     => $me,
            'since'   => $this->app->ts($now - 60 * max(0, $s->int('rematch_cooldown_minutes'))),
            'me4'     => $me,
            'me5'     => $me,
        ];
        $expires = $this->app->ts($now + max(10, $s->int('search_timeout_seconds')));
        $quota = $this->app->quota();

        return $this->app->db->tx(function (Database $db) use ($me, $user, $p, $expires, $today, $quota) {
            $this->lock($db);
            if ($db->value('SELECT chat_id FROM {active_chats} WHERE user_id = ?', [$me])) {
                return ['status' => 'in_chat'];
            }
            $queued = $db->row('SELECT status, expires_at FROM {chat_queue} WHERE user_id = ?', [$me]);
            $waiting = $queued && $queued['status'] === 'waiting' && $queued['expires_at'] > $p['now'];
            if (!$quota->hasAvailable($me)) {
                $db->run("UPDATE {chat_queue} SET status = 'cancelled' WHERE user_id = ? AND status = 'waiting'", [$me]);
                return ['status' => 'no_credits'];
            }

            $dropped = [];
            while ($partner = $this->candidate($db, $p, $dropped)) {
                if (!$quota->hasAvailable($partner)) {
                    // Can only happen if the admin lowered the limit while they waited.
                    $db->run("UPDATE {chat_queue} SET status = 'expired' WHERE user_id = ?", [$partner]);
                    $dropped[] = $partner;
                    continue;
                }
                $chatId = $db->insert('chats', [
                    'user_one_id'     => $partner, // waited longer
                    'user_two_id'     => $me,
                    'status'          => 'active',
                    'started_at'      => $p['now'],
                    'usage_date'      => $today,
                    'last_message_at' => $p['now'],
                ]);
                $c1 = $quota->consume($partner, $chatId);
                $c2 = $quota->consume($me, $chatId);
                if ($c1 === null || $c2 === null) {
                    throw new \RuntimeException('quota changed during matching'); // rolls everything back
                }
                $db->run('UPDATE {chats} SET user_one_credit = ?, user_two_credit = ? WHERE id = ?', [$c1, $c2, $chatId]);
                // Fails with a duplicate key (and rolls back) if either is somehow already chatting.
                $db->run('INSERT INTO {active_chats} (user_id, chat_id, partner_user_id) VALUES (?, ?, ?), (?, ?, ?)', [$partner, $chatId, $me, $me, $chatId, $partner]);
                $db->run("UPDATE {chat_queue} SET status = 'matched' WHERE user_id IN (?, ?)", [$me, $partner]);
                return ['status' => 'matched', 'chat_id' => $chatId, 'partner_id' => $partner, 'dropped' => $dropped];
            }

            if ($waiting) {
                // Still searching: renew instead of adding a second entry.
                $db->run('UPDATE {chat_queue} SET expires_at = ?, preferred_gender = ? WHERE user_id = ?', [$expires, $user['preferred_gender'], $me]);
                return ['status' => 'renewed', 'dropped' => $dropped];
            }
            $db->run(
                "INSERT INTO {chat_queue} (user_id, preferred_gender, queued_at, expires_at, status) VALUES (?, ?, ?, ?, 'waiting')
                 ON DUPLICATE KEY UPDATE preferred_gender = VALUES(preferred_gender), queued_at = VALUES(queued_at), expires_at = VALUES(expires_at), status = 'waiting'",
                [$me, $user['preferred_gender'], $p['now'], $expires]
            );
            return ['status' => 'queued', 'dropped' => $dropped];
        });
    }

    /** Longest-waiting compatible user, locked. Both preferences must fit, no block either way. */
    private function candidate(Database $db, array $p, array $skip): ?int
    {
        $skipSql = $skip ? ' AND q.user_id NOT IN (' . implode(',', array_map('intval', $skip)) . ')' : '';
        $id = $db->value(
            "SELECT q.user_id FROM {chat_queue} q
             JOIN {users} u ON u.id = q.user_id
             WHERE q.status = 'waiting' AND q.expires_at > :now AND q.user_id <> :me
               AND u.status = 'active' AND u.profile_completed = 1
               AND (:pref1 = 'any' OR u.gender = :pref2)
               AND (q.preferred_gender = 'any' OR q.preferred_gender = :gender)
               AND ((u.birth_date <= :cutoff) = :adult)
               AND NOT EXISTS (SELECT 1 FROM {blocked_users} b
                               WHERE (b.blocker_user_id = :me2 AND b.blocked_user_id = q.user_id)
                                  OR (b.blocker_user_id = q.user_id AND b.blocked_user_id = :me3))
               AND NOT EXISTS (SELECT 1 FROM {active_chats} a WHERE a.user_id = q.user_id)
               AND NOT EXISTS (SELECT 1 FROM {chats} c WHERE c.started_at > :since
                               AND ((c.user_one_id = :me4 AND c.user_two_id = q.user_id) OR (c.user_one_id = q.user_id AND c.user_two_id = :me5)))
               {$skipSql}
             ORDER BY q.queued_at ASC, q.id ASC
             LIMIT 1
             FOR UPDATE",
            $p
        );
        return $id === null ? null : (int) $id;
    }

    /** @return bool true when a waiting search was cancelled */
    public function cancel(int $userId): bool
    {
        return $this->app->db->tx(function (Database $db) use ($userId) {
            $this->lock($db);
            return $db->run("UPDATE {chat_queue} SET status = 'cancelled' WHERE user_id = ? AND status = 'waiting'", [$userId])->rowCount() > 0;
        });
    }

    public function isSearching(int $userId): bool
    {
        return (bool) $this->app->db->value("SELECT 1 FROM {chat_queue} WHERE user_id = ? AND status = 'waiting' AND expires_at > ?", [$userId, $this->app->ts()]);
    }

    /** @return array{chat_id:int,partner_id:int}|null */
    public function activeChat(int $userId): ?array
    {
        $a = $this->app->db->row('SELECT chat_id, partner_user_id FROM {active_chats} WHERE user_id = ?', [$userId]);
        return $a ? ['chat_id' => (int) $a['chat_id'], 'partner_id' => (int) $a['partner_user_id']] : null;
    }

    /**
     * Ends the active chat of $userId. With $expectedChatId (from a button) it only ends
     * that chat, so an old button can never end a newer chat.
     * @return array{chat_id:int,partner_id:int}|null null = nothing to end
     */
    public function end(int $userId, ?int $expectedChatId, string $reason): ?array
    {
        return $this->app->db->tx(function (Database $db) use ($userId, $expectedChatId, $reason) {
            $this->lock($db);
            $a = $db->row('SELECT chat_id, partner_user_id FROM {active_chats} WHERE user_id = ?', [$userId]);
            if (!$a || ($expectedChatId !== null && (int) $a['chat_id'] !== $expectedChatId)) {
                return null;
            }
            $this->close($db, (int) $a['chat_id'], $userId, $reason);
            return ['chat_id' => (int) $a['chat_id'], 'partner_id' => (int) $a['partner_user_id']];
        });
    }

    /** Ends a chat by id (idle timeout, unreachable user, suspension). @return int[] its two users, or [] */
    public function endById(int $chatId, string $reason, ?int $byUserId = null): array
    {
        return $this->app->db->tx(function (Database $db) use ($chatId, $reason, $byUserId) {
            $this->lock($db);
            $users = array_map('intval', array_column($db->all('SELECT user_id FROM {active_chats} WHERE chat_id = ?', [$chatId]), 'user_id'));
            if (!$users) {
                return [];
            }
            $this->close($db, $chatId, $byUserId, $reason);
            return $users;
        });
    }

    /** Waiting entries whose time is up → 'expired'. @return int[] user ids to tell "nobody found" */
    public function expireSearches(): array
    {
        $now = $this->app->ts();
        return $this->app->db->tx(function (Database $db) use ($now) {
            $this->lock($db);
            $ids = array_map('intval', array_column($db->all("SELECT user_id FROM {chat_queue} WHERE status = 'waiting' AND expires_at <= ? FOR UPDATE", [$now]), 'user_id'));
            if ($ids) {
                $db->run("UPDATE {chat_queue} SET status = 'expired' WHERE status = 'waiting' AND user_id IN (" . implode(',', $ids) . ')');
            }
            return $ids;
        });
    }

    /** Active chats without messages for $minutes. @return int[] chat ids */
    public function idleChats(int $minutes): array
    {
        if ($minutes <= 0) {
            return [];
        }
        $before = $this->app->ts($this->app->now() - 60 * $minutes);
        return array_map('intval', array_column($this->app->db->all("SELECT id FROM {chats} WHERE status = 'active' AND last_message_at < ? LIMIT 50", [$before]), 'id'));
    }

    /** Last chat between two users that $userId took part in (for buttons on ended chats). */
    public function chatOf(int $chatId, int $userId): ?array
    {
        $c = $this->app->db->row('SELECT * FROM {chats} WHERE id = ? AND (user_one_id = ? OR user_two_id = ?)', [$chatId, $userId, $userId]);
        if (!$c) {
            return null;
        }
        $c['partner_id'] = (int) ($c['user_one_id'] == $userId ? $c['user_two_id'] : $c['user_one_id']);
        return $c;
    }

    private function close(Database $db, int $chatId, ?int $byUserId, string $reason): void
    {
        $db->run('DELETE FROM {active_chats} WHERE chat_id = ?', [$chatId]);
        $db->run("UPDATE {chats} SET status = 'ended', ended_at = ?, ended_by_user_id = ?, end_reason = ? WHERE id = ? AND status = 'active'", [$this->app->ts(), $byUserId, $reason, $chatId]);
    }

    private function lock(Database $db): void
    {
        if ($db->value("SELECT name FROM {locks} WHERE name = 'matching' FOR UPDATE") === null) {
            $db->run("INSERT IGNORE INTO {locks} (name) VALUES ('matching')");
            $db->value("SELECT name FROM {locks} WHERE name = 'matching' FOR UPDATE");
        }
    }
}
