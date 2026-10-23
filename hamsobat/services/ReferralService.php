<?php
// Referral links: https://ble.ir/<bot>?start=ref<code>.
//
// Rules: the invited account must be new (created through the link, or created minutes
// ago without a finished profile), one inviter per account (UNIQUE invited_user_id), no
// self-referral. A referral only counts ("qualified") after the invited user finishes the
// profile (and joins the channel, if that setting is on). The reward is a ledger entry
// keyed on the referral id, so it is given exactly once, and capped per inviter per day.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class ReferralService
{
    public function __construct(private App $app)
    {
    }

    public function link(array $user): string
    {
        return $this->app->deepLink('ref' . $user['referral_code']);
    }

    /**
     * Records who invited $invited. @return array|null the inviter row when it was recorded
     */
    public function attach(array $invited, string $code, bool $justCreated): ?array
    {
        $db = $this->app->db;
        $inviter = $db->row('SELECT * FROM {users} WHERE referral_code = ?', [$code]);
        if (!$inviter || (int) $inviter['id'] === (int) $invited['id'] || $inviter['status'] !== 'active') {
            return null;
        }
        $windowStart = $this->app->now() - 60 * max(0, $this->app->settings->int('referral_attach_minutes'));
        $isNew = $justCreated || (
            !$invited['profile_completed']
            && $invited['referred_by_user_id'] === null
            && strtotime($invited['created_at'] . ' UTC') >= $windowStart
        );
        if (!$isNew) {
            return null;
        }
        try {
            $db->tx(function (Database $db) use ($inviter, $invited) {
                $db->insert('referrals', [
                    'inviter_user_id' => $inviter['id'],
                    'invited_user_id' => $invited['id'],
                    'status'          => 'pending',
                    'created_at'      => $this->app->ts(),
                ]);
                $db->run('UPDATE {users} SET referred_by_user_id = ? WHERE id = ? AND referred_by_user_id IS NULL', [$inviter['id'], $invited['id']]);
            });
        } catch (\PDOException $e) {
            if (Database::isDuplicate($e)) {
                return null; // already has an inviter
            }
            throw $e;
        }
        return $inviter;
    }

    /**
     * Qualifies the pending referral of $invited when the rules are met, and rewards the
     * inviter once. Safe to call often (profile done, channel joined, requirement check).
     * @return array|null ['inviter' => row, 'credits' => int] when it qualified now
     */
    public function tryQualify(array $invited): ?array
    {
        $db = $this->app->db;
        $ref = $db->row("SELECT * FROM {referrals} WHERE invited_user_id = ? AND status = 'pending'", [$invited['id']]);
        if (!$ref || !$invited['profile_completed'] || Users::missing($invited)) {
            return null;
        }
        if ($this->app->settings->bool('referral_requires_channel') && !$this->app->requirements()->isChannelMember($invited)) {
            return null; // tried again on the next requirement check
        }
        $reward = max(0, $this->app->settings->int('referral_reward_credits'));
        $cap = max(0, $this->app->settings->int('referral_daily_reward_cap'));
        $dayStart = $this->dayStartUtc();

        $credits = $db->tx(function (Database $db) use ($ref, $reward, $cap, $dayStart) {
            $row = $db->row('SELECT status FROM {referrals} WHERE id = ? FOR UPDATE', [$ref['id']]);
            if (!$row || $row['status'] !== 'pending') {
                return null;
            }
            $today = (int) $db->value("SELECT COUNT(*) FROM {referrals} WHERE inviter_user_id = ? AND status = 'qualified' AND reward_credits > 0 AND qualified_at >= ?", [$ref['inviter_user_id'], $dayStart]);
            $credits = $today < $cap ? $reward : 0;
            $db->run("UPDATE {referrals} SET status = 'qualified', qualified_at = ?, reward_credits = ? WHERE id = ?", [$this->app->ts(), $credits, $ref['id']]);
            if ($credits > 0) {
                $this->app->quota()->grant((int) $ref['inviter_user_id'], $credits, 'referral', (string) $ref['id']);
            }
            return $credits;
        });
        if ($credits === null) {
            return null;
        }
        return ['inviter' => $this->app->users()->get((int) $ref['inviter_user_id']), 'credits' => $credits];
    }

    /**
     * Pending referrals of friends who finished their profile: retried in the hourly cleanup,
     * so a friend who joins the channel later still counts even if they never press anything.
     * Newest first and only the last 14 days, so a few friends who never join cannot take every
     * slot. Older ones still count the next time that friend uses the bot (find() qualifies).
     * @return array<int, array> the invited users to try
     */
    public function pendingToRetry(int $limit = 30): array
    {
        return $this->app->db->all(
            "SELECT u.* FROM {referrals} r JOIN {users} u ON u.id = r.invited_user_id
             WHERE r.status = 'pending' AND r.created_at >= ? AND u.profile_completed = 1 AND u.status = 'active'
             ORDER BY r.id DESC LIMIT " . (int) $limit,
            [$this->app->ts($this->app->now() - 14 * 86400)]
        );
    }

    public function qualifiedCount(int $userId): int
    {
        return (int) $this->app->db->value("SELECT COUNT(*) FROM {referrals} WHERE inviter_user_id = ? AND status = 'qualified'", [$userId]);
    }

    public function pendingCount(int $userId): int
    {
        return (int) $this->app->db->value("SELECT COUNT(*) FROM {referrals} WHERE inviter_user_id = ? AND status = 'pending'", [$userId]);
    }

    /** Midnight of today (app timezone) as a UTC DATETIME. */
    private function dayStartUtc(): string
    {
        $tz = new \DateTimeZone($this->app->config['timezone'] ?? 'Asia/Tehran');
        return $this->app->ts((new \DateTimeImmutable($this->app->today() . ' 00:00:00', $tz))->getTimestamp());
    }
}
