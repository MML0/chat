<?php
// Daily free chats + bonus credits (from referrals or the admin).
//
// A chat is counted only when two users are actually matched: MatchingService calls
// consume() inside the same transaction that creates the chat, so a search that finds
// nobody, or a cancelled search, never costs anything. Every credit change is a ledger
// row with UNIQUE (user, reason, ref): a reward or a spend can never happen twice.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class QuotaService
{
    public function __construct(private App $app)
    {
    }

    /** @return array{limit:int,used:int,free_left:int,credits:int,total:int,unlimited:bool,unlimited_until:?string} */
    public function status(int $userId): array
    {
        $db = $this->app->db;
        $limit = max(0, $this->app->settings->int('daily_free_chats'));
        $row = $db->row('SELECT successful_chats, bonus_credits_used FROM {daily_usage} WHERE user_id = ? AND usage_date = ?', [$userId, $this->app->today()]);
        $freeUsed = $row ? (int) $row['successful_chats'] - (int) $row['bonus_credits_used'] : 0;
        $credits = max(0, (int) $db->value('SELECT credit_balance FROM {user_credits} WHERE user_id = ?', [$userId]));
        $freeLeft = max(0, $limit - $freeUsed);
        $perk = $this->unlimited($userId);
        return [
            'limit'           => $limit,
            'used'            => $row ? (int) $row['successful_chats'] : 0,
            'free_left'       => $freeLeft,
            'credits'         => $credits,
            'total'           => $perk ? 1000000 : $freeLeft + $credits,
            'unlimited'       => (bool) $perk,
            'unlimited_until' => $perk['unlimited_until'] ?? null,
        ];
    }

    /** The active "unlimited chats" gift from the admin, or null. */
    public function unlimited(int $userId): ?array
    {
        try {
            $p = $this->app->db->row('SELECT * FROM {user_perks} WHERE user_id = ? AND unlimited = 1', [$userId]);
        } catch (\PDOException) {
            return null; // migrate.php not run yet
        }
        if (!$p || ($p['unlimited_until'] !== null && strtotime($p['unlimited_until'] . ' UTC') <= $this->app->now())) {
            return null;
        }
        return $p;
    }

    /** $until = null → unlimited until the admin removes it. */
    public function setUnlimited(int $userId, bool $on, ?int $until = null, string $note = ''): void
    {
        $this->app->db->run(
            'INSERT INTO {user_perks} (user_id, unlimited, unlimited_until, note, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE unlimited = VALUES(unlimited), unlimited_until = VALUES(unlimited_until), note = VALUES(note), updated_at = VALUES(updated_at)',
            [$userId, $on ? 1 : 0, $on && $until ? $this->app->ts($until) : null, mb_substr($note, 0, 200), $this->app->ts()]
        );
    }

    /** Admin gift: today's used free chats are given back. */
    public function resetToday(int $userId): void
    {
        $this->app->db->run('UPDATE {daily_usage} SET successful_chats = bonus_credits_used WHERE user_id = ? AND usage_date = ?', [$userId, $this->app->today()]);
    }

    public function hasAvailable(int $userId): bool
    {
        return $this->status($userId)['total'] > 0;
    }

    /**
     * Spends one chat for $userId (free first, then a credit). MUST run inside a transaction.
     * @return 'free'|'credit'|null null = nothing left (the caller rolls back)
     */
    public function consume(int $userId, int $chatId): ?string
    {
        $db = $this->app->db;
        $day = $this->app->today();
        $db->run('INSERT INTO {daily_usage} (user_id, usage_date) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = id', [$userId, $day]);
        $row = $db->row('SELECT id, successful_chats, bonus_credits_used FROM {daily_usage} WHERE user_id = ? AND usage_date = ? FOR UPDATE', [$userId, $day]);
        $freeUsed = (int) $row['successful_chats'] - (int) $row['bonus_credits_used'];
        if ($freeUsed < $this->app->settings->int('daily_free_chats') || $this->unlimited($userId)) {
            $db->run('UPDATE {daily_usage} SET successful_chats = successful_chats + 1 WHERE id = ?', [$row['id']]);
            return 'free';
        }
        if (!$this->change($userId, -1, 'chat', (string) $chatId)) {
            return null;
        }
        $db->run('UPDATE {daily_usage} SET successful_chats = successful_chats + 1, bonus_credits_used = bonus_credits_used + 1 WHERE id = ?', [$row['id']]);
        return 'credit';
    }

    /** Gives back what consume() took for a chat that did not really happen. Idempotent. */
    public function refund(int $userId, int $chatId, string $kind, string $usageDate): void
    {
        $db = $this->app->db;
        $db->tx(function (Database $db) use ($userId, $chatId, $kind, $usageDate) {
            if ($kind === 'credit') {
                if ($this->change($userId, +1, 'refund', (string) $chatId)) {
                    $db->run('UPDATE {daily_usage} SET successful_chats = GREATEST(successful_chats - 1, 0), bonus_credits_used = GREATEST(bonus_credits_used - 1, 0) WHERE user_id = ? AND usage_date = ?', [$userId, $usageDate]);
                }
                return;
            }
            // Free chat: the ledger row (delta 0) makes the refund happen only once.
            try {
                $db->run('INSERT INTO {credit_ledger} (user_id, delta, reason, ref_id, balance_after, created_at) VALUES (?, 0, ?, ?, 0, ?)', [$userId, 'refund_free', (string) $chatId, $this->app->ts()]);
            } catch (\PDOException $e) {
                if (Database::isDuplicate($e)) {
                    return;
                }
                throw $e;
            }
            $db->run('UPDATE {daily_usage} SET successful_chats = GREATEST(successful_chats - 1, 0) WHERE user_id = ? AND usage_date = ?', [$userId, $usageDate]);
        });
    }

    /** Adds credits once per (reason, ref). Returns false when it was already given. */
    public function grant(int $userId, int $amount, string $reason, string $ref): bool
    {
        return $amount > 0 && (bool) $this->app->db->tx(fn () => $this->change($userId, $amount, $reason, $ref));
    }

    /**
     * Balance change + ledger row. Must run inside a transaction. A negative change that
     * would go below 0, or a repeated (reason, ref), changes nothing and returns false.
     */
    private function change(int $userId, int $delta, string $reason, string $ref): bool
    {
        $db = $this->app->db;
        $now = $this->app->ts();
        $db->run('INSERT INTO {user_credits} (user_id, credit_balance, updated_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE id = id', [$userId, $now]);
        $balance = (int) $db->value('SELECT credit_balance FROM {user_credits} WHERE user_id = ? FOR UPDATE', [$userId]);
        if ($balance + $delta < 0) {
            return false;
        }
        try {
            $db->run('INSERT INTO {credit_ledger} (user_id, delta, reason, ref_id, balance_after, created_at) VALUES (?, ?, ?, ?, ?, ?)', [$userId, $delta, $reason, $ref, $balance + $delta, $now]);
        } catch (\PDOException $e) {
            if (Database::isDuplicate($e)) {
                return false;
            }
            throw $e;
        }
        $db->run('UPDATE {user_credits} SET credit_balance = credit_balance + ?, updated_at = ? WHERE user_id = ?', [$delta, $now, $userId]);
        return true;
    }
}
