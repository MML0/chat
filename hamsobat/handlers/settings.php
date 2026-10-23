<?php
// ⚙️ Settings: partner gender, privacy toggles, blocked users.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class SettingsHandler extends Handler
{
    /** toggle name → users column */
    private const TOGGLES = ['notify' => 'notify_profile_views', 'city' => 'show_city', 'bio' => 'show_bio', 'photo' => 'show_photo'];

    public function menu(array $user, ?array $cb = null): void
    {
        if (!$user['profile_completed']) {
            $this->say($user, '📝 اول پروفایلت رو کامل کن.', Ui::onboardingKeyboard());
            return;
        }
        $on = fn (string $col) => $user[$col] ? '✅' : '❌';
        $blocked = (int) $this->app->db->value('SELECT COUNT(*) FROM {blocked_users} WHERE blocker_user_id = ?', [$user['id']]);
        $text = "⚙️ تنظیمات\n\nبا زدن هر دکمه، اون تنظیم عوض می‌شه.";
        $markup = Ui::inline([
            [['🎯 جنسیت هم‌صحبت: ' . Ui::genderName($user['preferred_gender']), 'st:pref']],
            [['👀 اعلان بازدید پروفایل: ' . $on('notify_profile_views'), 'st:t:notify']],
            [['📍 نمایش شهر: ' . $on('show_city'), 'st:t:city'], ['📝 نمایش درباره من: ' . $on('show_bio'), 'st:t:bio']],
            [['🖼 نمایش عکس: ' . $on('show_photo'), 'st:t:photo']],
            [['🚫 کاربران مسدود (' . Text::fa($blocked) . ')', 'st:blocks']],
            [['✏️ ویرایش پروفایل', 'pf:edit'], ['ℹ️ راهنما', 'st:guide']],
        ]);
        $cb ? $this->editOrSend($user, $cb, $text, $markup) : $this->say($user, $text, $markup);
    }

    public function onCallback(array $user, string $data, array $cb): string|array|null
    {
        $id = (int) $user['id'];
        if ($data === 'st:home') {
            $this->menu($user);
            return null;
        }
        if ($data === 'st:guide') {
            $this->app->handler(StartHandler::class)->guide($user);
            return null;
        }
        if (preg_match('~^st:t:(notify|city|bio|photo)$~', $data, $m)) {
            $col = self::TOGGLES[$m[1]];
            $this->app->users()->update($id, [$col => $user[$col] ? 0 : 1]);
            $this->menu($this->fresh($user), $cb);
            return '✅ ذخیره شد';
        }
        if ($data === 'st:pref') {
            $this->editOrSend($user, $cb, '🎯 دوست داری با کی هم‌صحبت بشی؟', Ui::inline([
                [['👩 دختر', 'st:pg:f'], ['👨 پسر', 'st:pg:m']],
                [['🎲 فرقی نمی‌کنه', 'st:pg:any']],
                [['🔙 برگشت', 'st:back']],
            ]));
            return null;
        }
        if (preg_match('~^st:pg:(m|f|any)$~', $data, $m)) {
            $this->app->users()->update($id, ['preferred_gender' => $m[1]]);
            $this->menu($this->fresh($user), $cb);
            return '✅ ذخیره شد';
        }
        if ($data === 'st:back') {
            $this->menu($user, $cb);
            return null;
        }
        if ($data === 'st:blocks') {
            $this->blocks($user, $cb);
            return null;
        }
        if (preg_match('~^st:ub:(\d{1,10})$~', $data, $m)) {
            $done = $this->app->moderation()->unblock($id, (int) $m[1]);
            $this->blocks($user, $cb);
            return $done ? '🔓 رفع مسدودی شد' : null;
        }
        return null;
    }

    private function blocks(array $user, array $cb): void
    {
        $list = $this->app->moderation()->blockedList((int) $user['id']);
        $rows = [];
        foreach ($list as $i => $b) {
            $name = $b['had_chat'] && $b['nickname'] ? $b['nickname'] : 'فرستنده ناشناس ' . Text::fa($i + 1);
            $rows[] = [['🔓 ' . $name, "st:ub:{$b['id']}"]];
        }
        $rows[] = [['🔙 برگشت', 'st:back']];
        $text = $list ? "🚫 کاربران مسدود\nبرای رفع مسدودی روی هر کدوم بزن." : '🚫 کسی رو مسدود نکردی.';
        $this->editOrSend($user, $cb, $text, Ui::inline($rows));
    }
}
