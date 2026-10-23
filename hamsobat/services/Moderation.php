<?php
// Blocks, reports and suspensions.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Moderation
{
    public const REASONS = [
        'msg' => 'پیام نامناسب',
        'har' => 'مزاحمت',
        'ill' => 'محتوای غیرمجاز',
        'oth' => 'سایر موارد',
    ];

    public function __construct(private App $app)
    {
    }

    /** @return bool true when the block is new */
    public function block(int $blocker, int $blocked): bool
    {
        if ($blocker === $blocked) {
            return false;
        }
        return $this->app->db->run('INSERT IGNORE INTO {blocked_users} (blocker_user_id, blocked_user_id, created_at) VALUES (?, ?, ?)', [$blocker, $blocked, $this->app->ts()])->rowCount() > 0;
    }

    public function unblock(int $blocker, int $blocked): bool
    {
        return $this->app->db->run('DELETE FROM {blocked_users} WHERE blocker_user_id = ? AND blocked_user_id = ?', [$blocker, $blocked])->rowCount() > 0;
    }

    /** $blocker has blocked $blocked (one direction). */
    public function hasBlocked(int $blocker, int $blocked): bool
    {
        return (bool) $this->app->db->value('SELECT 1 FROM {blocked_users} WHERE blocker_user_id = ? AND blocked_user_id = ?', [$blocker, $blocked]);
    }

    /**
     * @return array [['id' => …, 'nickname' => …, 'had_chat' => 0|1], …] newest first.
     * The nickname may only be shown when they chatted: an inbox sender stays anonymous.
     */
    public function blockedList(int $blocker, int $limit = 20): array
    {
        return $this->app->db->all(
            'SELECT u.id, u.nickname, EXISTS (SELECT 1 FROM {chats} c WHERE (c.user_one_id = b.blocker_user_id AND c.user_two_id = u.id)
                                                OR (c.user_one_id = u.id AND c.user_two_id = b.blocker_user_id)) AS had_chat
             FROM {blocked_users} b JOIN {users} u ON u.id = b.blocked_user_id
             WHERE b.blocker_user_id = ? ORDER BY b.id DESC LIMIT ' . (int) $limit,
            [$blocker]
        );
    }

    /**
     * @return array{status:string,id?:int} status: created | duplicate | limited
     */
    public function report(int $reporter, int $reported, ?int $chatId, ?int $inboxId, string $reason): array
    {
        $db = $this->app->db;
        if (!isset(self::REASONS[$reason])) {
            throw new \InvalidArgumentException('bad reason');
        }
        $dup = $chatId !== null
            ? $db->value('SELECT id FROM {reports} WHERE reporter_user_id = ? AND chat_id = ?', [$reporter, $chatId])
            : $db->value('SELECT id FROM {reports} WHERE reporter_user_id = ? AND inbox_message_id = ?', [$reporter, $inboxId]);
        if ($dup) {
            return ['status' => 'duplicate', 'id' => (int) $dup];
        }
        if (!$this->app->rate()->allow($reporter, 'report', $this->app->settings->int('report_rate_per_day'), 86400)) {
            return ['status' => 'limited'];
        }
        $id = $db->insert('reports', [
            'reporter_user_id' => $reporter,
            'reported_user_id' => $reported,
            'chat_id'          => $chatId,
            'inbox_message_id' => $inboxId,
            'reason'           => $reason,
            'created_at'       => $this->app->ts(),
            'status'           => 'open',
        ]);
        return ['status' => 'created', 'id' => $id];
    }

    public function setDetails(int $reportId, int $reporter, string $details): void
    {
        $this->app->db->run('UPDATE {reports} SET details = ? WHERE id = ? AND reporter_user_id = ?', [mb_substr($details, 0, 500), $reportId, $reporter]);
    }

    /** Suspends for 24 h when enough different people reported the user in the last 24 h. */
    public function autoSuspendIfNeeded(int $reported): bool
    {
        $limit = $this->app->settings->int('auto_suspend_reports');
        if ($limit <= 0) {
            return false;
        }
        $reporters = (int) $this->app->db->value(
            "SELECT COUNT(DISTINCT reporter_user_id) FROM {reports} WHERE reported_user_id = ? AND created_at >= ? AND status <> 'dismissed'",
            [$reported, $this->app->ts($this->app->now() - 86400)]
        );
        $user = $this->app->users()->get($reported);
        if ($reporters < $limit || !$user || $user['status'] === 'suspended' || $this->app->isAdmin((int) $user['bale_user_id'])) {
            return false;
        }
        $this->suspend($reported, 24);
        $this->app->notifyAdmins("⛔ کاربر #{$reported} ({$user['nickname']}) به‌خاطر {$reporters} گزارش در ۲۴ ساعت، خودکار ۲۴ ساعت تعلیق شد.\nلغو: /unsuspend {$reported}");
        return true;
    }

    /** $hours = 0 → until the admin lifts it. Ends the user's chat and search. */
    public function suspend(int $userId, int $hours): void
    {
        $until = $hours > 0 ? $this->app->ts($this->app->now() + 3600 * $hours) : null;
        $this->app->db->run("UPDATE {users} SET status = 'suspended', suspended_until = ? WHERE id = ?", [$until, $userId]);
        $this->app->matching()->cancel($userId);
        $ended = $this->app->matching()->end($userId, null, 'suspended');
        if ($ended) {
            $partner = $this->app->users()->get($ended['partner_id']);
            if ($partner) {
                $this->app->bale->send((int) $partner['bale_user_id'], '🏁 هم‌صحبتت چت رو تموم کرد.', Ui::mainKeyboard());
            }
        }
        $user = $this->app->users()->get($userId);
        if ($user) {
            $this->app->bale->send((int) $user['bale_user_id'], '⛔ حساب تو به‌خاطر گزارش‌های کاربران ' . ($hours > 0 ? Text::fa($hours) . ' ساعت' : 'تا اطلاع بعدی') . ' مسدود شد.', Ui::removeKeyboard());
        }
    }

    public function unsuspend(int $userId): bool
    {
        return $this->app->db->run("UPDATE {users} SET status = 'active', suspended_until = NULL WHERE id = ? AND status = 'suspended'", [$userId])->rowCount() > 0;
    }
}
