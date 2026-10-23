<?php
// Inside a random chat: relay, next, end, view profile, block, report.
// Buttons carry the chat id (ch:nx:<chatId>), so an old button never touches a newer chat,
// and every press re-checks on the server that the user really was in that chat.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class ChatHandler extends Handler
{
    public const ENDED_BY_PARTNER = '🏁 هم‌صحبتت چت رو تموم کرد.';

    public function onMessage(array $user, array $chat, array $m, ?string $label): void
    {
        $chatId = $chat['chat_id'];
        match ($label) {
            Ui::NEXT         => $this->next($user, null),
            Ui::END          => $this->end($user, null),
            Ui::VIEW_PROFILE => $this->viewProfile($user, $chatId),
            Ui::BLOCK        => $this->confirmBlock($user, $chatId),
            Ui::REPORT       => $this->askReason($user, 'c', $chatId),
            null             => $this->relay($user, $chat, $m),
            default          => $this->say($user, '💬 تو وسط یک چتی. برای منو اول «' . Ui::END . '» رو بزن.', Ui::chatKeyboard()),
        };
    }

    private function relay(array $user, array $chat, array $m): void
    {
        $partner = $this->app->users()->get($chat['partner_id']);
        $r = $this->app->relay()->relayChat($user, $partner, $chat['chat_id'], $m);
        switch ($r['status']) {
            case 'filtered':
                $this->say($user, $r['error']);
                break;
            case 'limited':
                if (!empty($r['notify'])) {
                    $this->say($user, '⏳ کمی آهسته‌تر 🙂 چند ثانیه صبر کن.');
                }
                break;
            case 'unreachable':
                $this->app->matching()->endById($chat['chat_id'], 'unreachable');
                $this->say($user, '😕 هم‌صحبتت از ربات خارج شده و چت تموم شد.', Ui::mainKeyboard());
                break;
            case 'failed':
                $this->say($user, '❌ پیامت ارسال نشد. دوباره امتحان کن.');
                break;
        }
    }

    /** ➡️ نفر بعدی: end this chat and search again right away. */
    public function next(array $user, ?int $chatId): ?string
    {
        $ended = $this->app->matching()->end((int) $user['id'], $chatId, 'next');
        if (!$ended) {
            if ($chatId !== null) {
                return 'این چت قبلاً تموم شده.';
            }
            $this->app->handler(MatchingHandler::class)->find($user);
            return null;
        }
        $this->tellPartnerEnded($ended['partner_id'], $ended['chat_id']);
        $this->say($user, '🔄 رفتیم سراغ نفر بعدی...');
        $this->app->handler(MatchingHandler::class)->find($user);
        return null;
    }

    /** 🏁 پایان چت */
    public function end(array $user, ?int $chatId): ?string
    {
        $ended = $this->app->matching()->end((int) $user['id'], $chatId, 'end');
        if (!$ended) {
            if ($chatId !== null) {
                return 'این چت قبلاً تموم شده.';
            }
            $this->home($user, '💬 الان در چتی نیستی.');
            return null;
        }
        $this->tellPartnerEnded($ended['partner_id'], $ended['chat_id']);
        $id = $ended['chat_id'];
        $this->say($user, "🏁 چت تموم شد.\nاگه مشکلی بود می‌تونی گزارش بدی یا مسدودش کنی 👇", Ui::inline([
            [['🚩 گزارش', "ch:rp:{$id}"], ['🚫 مسدود کردن', "ch:bl:{$id}"]],
        ]));
        $this->say($user, '🏠 منوی اصلی', Ui::mainKeyboard());
        return null;
    }

    /** The other side of an ended chat. Never says whether they were blocked. */
    public function tellPartnerEnded(int $partnerId, int $chatId, string $text = self::ENDED_BY_PARTNER): void
    {
        $p = $this->app->users()->get($partnerId);
        if (!$p) {
            return;
        }
        $this->say($p, $text, Ui::inline([
            [['🔎 هم‌صحبت جدید', 'fd']],
            [['🚩 گزارش', "ch:rp:{$chatId}"], ['🚫 مسدود کردن', "ch:bl:{$chatId}"]],
        ]));
        $this->say($p, '🏠 منوی اصلی', Ui::mainKeyboard());
    }

    // ------------------------------------------------------------------ profile view

    public function viewProfile(array $user, int $chatId): ?string
    {
        $chat = $this->app->matching()->chatOf($chatId, (int) $user['id']);
        $other = $chat ? $this->app->users()->get($chat['partner_id']) : null;
        if (!$other) {
            return 'این چت پیدا نشد.';
        }
        $text = "👤 پروفایل هم‌صحبت\n\n" . Ui::card($other, $this->app->today(), $this->app->users()->interests((int) $other['id']), true);
        $sent = false;
        if ($other['photo_file_id'] && $other['show_photo']) {
            $sent = !empty($this->app->bale->sendMedia('photo', (int) $user['bale_user_id'], $other['photo_file_id'], $text)['ok']);
        }
        if (!$sent) {
            $this->say($user, $text);
        }
        $this->recordView($other, $user);
        return null;
    }

    /** Saves the view and tells the owner, at most once per viewer per N minutes. */
    private function recordView(array $owner, array $viewer): void
    {
        $db = $this->app->db;
        $s = $this->app->settings;
        $viewId = $db->insert('profile_views', ['profile_owner_id' => $owner['id'], 'viewer_user_id' => $viewer['id'], 'viewed_at' => $this->app->ts()]);
        if (!$s->bool('feature_profile_view_notify') || !$owner['notify_profile_views']) {
            return;
        }
        $since = $this->app->ts($this->app->now() - 60 * max(1, $s->int('profile_view_notify_minutes')));
        $recent = $db->value('SELECT 1 FROM {profile_views} WHERE profile_owner_id = ? AND viewer_user_id = ? AND notification_sent_at >= ?', [$owner['id'], $viewer['id'], $since]);
        if ($recent) {
            return;
        }
        $db->run('UPDATE {profile_views} SET notification_sent_at = ? WHERE id = ?', [$this->app->ts(), $viewId]);
        $this->say($owner, '👀 یکی پروفایلت رو دید!');
    }

    // ------------------------------------------------------------------ block

    private function confirmBlock(array $user, int $chatId): ?string
    {
        if (!$this->app->matching()->chatOf($chatId, (int) $user['id'])) {
            return 'این چت پیدا نشد.';
        }
        $this->say($user, "🚫 مطمئنی می‌خوای مسدودش کنی؟\nچت تموم می‌شه و دیگه هیچ‌وقت به هم وصل نمی‌شید.", Ui::inline([
            [['✅ بله، مسدود کن', "ch:bly:{$chatId}"], ['❌ نه', 'ch:no']],
        ]));
        return null;
    }

    private function block(array $user, int $chatId): ?string
    {
        $me = (int) $user['id'];
        $chat = $this->app->matching()->chatOf($chatId, $me);
        if (!$chat) {
            return 'این چت پیدا نشد.';
        }
        $this->app->moderation()->block($me, $chat['partner_id']);
        $ended = $this->app->matching()->end($me, $chatId, 'block');
        if ($ended) {
            $this->tellPartnerEnded($ended['partner_id'], $chatId); // same text as a normal end
        }
        $this->say($user, "🚫 مسدود شد. دیگه هیچ‌وقت به هم وصل نمی‌شید.\n(از ⚙️ تنظیمات می‌تونی رفعش کنی)", $ended ? Ui::mainKeyboard() : null);
        return null;
    }

    // ------------------------------------------------------------------ report (chats and inbox)

    /** $kind 'c' = random chat id, 'i' = anonymous inbox message id. */
    public function askReason(array $user, string $kind, int $refId): ?string
    {
        if (!$this->reportTarget($user, $kind, $refId)) {
            return 'پیدا نشد.';
        }
        $rows = [];
        foreach (Moderation::REASONS as $code => $title) {
            $rows[] = [[$title, "rr:{$kind}:{$refId}:{$code}"]];
        }
        $rows[] = [['لغو', 'ch:no']];
        $this->say($user, 'چرا می‌خوای این کاربر رو گزارش کنی؟', Ui::inline($rows));
        return null;
    }

    /** @return int|null the reported user's id, if $user may report this chat/message */
    private function reportTarget(array $user, string $kind, int $refId): ?int
    {
        if ($kind === 'c') {
            $chat = $this->app->matching()->chatOf($refId, (int) $user['id']);
            return $chat ? $chat['partner_id'] : null;
        }
        $sender = $this->app->db->value('SELECT sender_user_id FROM {anonymous_inbox} WHERE id = ? AND recipient_user_id = ?', [$refId, $user['id']]);
        return $sender ? (int) $sender : null;
    }

    private function report(array $user, string $kind, int $refId, string $reason): string|array|null
    {
        $reported = $this->reportTarget($user, $kind, $refId);
        if (!$reported || !isset(Moderation::REASONS[$reason])) {
            return 'پیدا نشد.';
        }
        $mod = $this->app->moderation();
        $r = $mod->report((int) $user['id'], $reported, $kind === 'c' ? $refId : null, $kind === 'i' ? $refId : null, $reason);
        if ($r['status'] === 'duplicate') {
            return ['text' => '✅ قبلاً این مورد رو گزارش کردی.', 'alert' => true];
        }
        if ($r['status'] === 'limited') {
            return ['text' => '⏳ امروز گزارش زیادی ثبت کردی. فردا دوباره امتحان کن.', 'alert' => true];
        }
        if ($this->app->settings->bool('notify_admin_reports')) {
            $target = $this->app->users()->get($reported);
            $this->app->notifyAdmins("🚩 گزارش جدید #{$r['id']}\nدلیل: " . Moderation::REASONS[$reason]
                . "\nگزارش‌دهنده: #{$user['id']} ({$user['nickname']})\nگزارش‌شده: #{$reported} (" . ($target['nickname'] ?? '?') . ')'
                . ($kind === 'c' ? "\nچت: #{$refId}" : "\nپیام ناشناس: #{$refId}") . "\nبررسی: /report {$r['id']}");
        }
        $mod->autoSuspendIfNeeded($reported);
        if ($reason === 'oth') {
            $this->app->users()->setState((int) $user['id'], 'report:details', ['report' => $r['id']], 900);
            $this->say($user, '✍️ لطفاً توضیح کوتاهی بنویس که چی شد:', Ui::inline([[['⏭ رد شدن', 'rd:skip']]]));
            return null;
        }
        $this->reported($user, $kind, $refId);
        return null;
    }

    private function reported(array $user, string $kind, int $refId): void
    {
        $block = $kind === 'c' ? "ch:bly:{$refId}" : "ix:b:{$refId}";
        $this->say($user, "✅ گزارشت ثبت شد و بررسی می‌شه. ممنون 🙏\nمی‌خوای مسدودش هم بکنی؟", Ui::inline([[['🚫 مسدود کردن', $block], ['❌ نه', 'ch:no']]]));
    }

    /** The text after choosing "سایر موارد". */
    public function onInput(array $user, string $state, array $data, array $m): bool
    {
        if ($state !== 'report:details' || empty($data['report'])) {
            $this->app->users()->clearState((int) $user['id']);
            return false;
        }
        if (!isset($m['text'])) {
            $this->say($user, '✍️ لطفاً توضیح رو به‌صورت متن بنویس.');
            return true;
        }
        $this->app->moderation()->setDetails((int) $data['report'], (int) $user['id'], Text::normalize((string) $m['text'], true));
        $this->app->users()->clearState((int) $user['id']);
        $this->say($user, '✅ گزارشت ثبت شد و بررسی می‌شه. ممنون 🙏', $this->keyboardFor($user));
        return true;
    }

    public function onCallback(array $user, string $data, array $cb): string|array|null
    {
        if ($data === 'ch:no') {
            return 'باشه 👌';
        }
        if ($data === 'rd:skip') {
            $this->app->users()->clearState((int) $user['id']);
            $this->say($user, '✅ گزارشت ثبت شد و بررسی می‌شه. ممنون 🙏', $this->keyboardFor($user));
            return null;
        }
        if (preg_match('~^ch:(pv|nx|bl|bly|end|rp):(\d{1,10})$~', $data, $m)) {
            $chatId = (int) $m[2];
            return match ($m[1]) {
                'pv'  => $this->viewProfile($user, $chatId),
                'nx'  => $this->next($user, $chatId),
                'bl'  => $this->confirmBlock($user, $chatId),
                'bly' => $this->block($user, $chatId),
                'end' => $this->end($user, $chatId),
                'rp'  => $this->askReason($user, 'c', $chatId),
            };
        }
        if (preg_match('~^rr:([ci]):(\d{1,10}):([a-z]{3})$~', $data, $m)) {
            return $this->report($user, $m[1], (int) $m[2], $m[3]);
        }
        return null;
    }
}
