<?php
// Access requirements (channel + referral) and the invite screen.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class ReferralHandler extends Handler
{
    /** Qualifies $user's own pending referral (if the rules are met now) and tells the inviter. */
    public function qualify(array $user): void
    {
        $r = $this->app->referrals()->tryQualify($user);
        if (!$r || !$r['inviter']) {
            return;
        }
        $inviter = $r['inviter'];
        $text = '🎉 یکی از دوستایی که دعوت کرده بودی ثبت‌نامش رو کامل کرد!';
        if ($r['credits'] > 0) {
            $text .= "\n🎁 " . Text::fa($r['credits']) . ' چت اضافه گرفتی.';
        }
        $needed = $this->app->settings->int('required_referrals');
        if ($needed > 0 && $this->app->referrals()->qualifiedCount((int) $inviter['id']) === $needed) {
            $text .= "\n\n🔓 چت ناشناس برات باز شد! «" . Ui::FIND . '» رو بزن.';
        }
        $this->app->bale->send((int) $inviter['bale_user_id'], $text);
    }

    /** After registration: show what is still missing, or that everything is ready. */
    public function showRequirementsOrReady(array $user): void
    {
        $req = $this->app->requirements()->status($user);
        $req['ok'] ? $this->ready($user) : $this->requirementsScreen($user, $req);
    }

    public function requirementsScreen(array $user, array $req, ?array $cb = null): void
    {
        [$text, $markup] = $this->screen($req);
        $cb ? $this->editOrSend($user, $cb, $text, $markup) : $this->say($user, $text, $markup);
    }

    private function screen(array $req): array
    {
        $steps = [];
        $rows = [];
        if ($req['channel_required']) {
            $channel = $this->app->settings->str('required_channel');
            $steps[] = ($req['channel'] ? '✅' : '⬜') . ' عضو کانال شو' . (str_starts_with($channel, '@') ? " ({$channel})" : '') . '.';
            $link = $this->app->settings->str('channel_link');
            if (!$req['channel'] && $link !== '') {
                $rows[] = [['📢 عضویت در کانال', 'url' => $link]];
            }
        }
        if ($req['refs_needed'] > 0) {
            $done = $req['refs'] >= $req['refs_needed'];
            $steps[] = ($done ? '✅' : '⬜') . ($req['refs_needed'] === 1 ? ' یک دوست' : ' ' . Text::fa($req['refs_needed']) . ' دوست')
                . ' رو به هم‌صحبت دعوت کن. (' . Text::fa(min($req['refs'], $req['refs_needed'])) . ' از ' . Text::fa($req['refs_needed']) . ')';
            if (!$done) {
                $rows[] = [['👥 دعوت یک دوست', 'inv']];
            }
        }
        $rows[] = [['🔄 بررسی شرایط', 'rq']];
        $numbered = [];
        foreach ($steps as $i => $s) {
            $numbered[] = Text::fa($i + 1) . '. ' . $s;
        }
        $title = count($steps) === 1 ? 'برای شروع چت، این مرحله رو انجام بده:' : 'برای شروع چت، این ' . (count($steps) === 2 ? 'دو' : Text::fa(count($steps))) . ' مرحله رو انجام بده:';
        $text = $title . "\n\n" . implode("\n", $numbered);
        if ($req['refs_needed'] > 0 && $req['refs'] < $req['refs_needed']) {
            $text .= "\n\n💡 دعوت وقتی حساب می‌شه که دوستت پروفایلش رو کامل کنه" . ($this->app->settings->bool('referral_requires_channel') && $req['channel_required'] ? ' و عضو کانال بشه' : '') . '.';
        }
        return [$text, Ui::inline($rows)];
    }

    private function ready(array $user, ?array $cb = null): void
    {
        $text = "🎉 عالیه! همه‌چیز آماده‌ست.\nحالا می‌تونی اولین هم‌صحبتت رو پیدا کنی!";
        $markup = Ui::inline([[['🔎 پیدا کردن هم‌صحبت', 'fd']]]);
        $cb ? $this->editOrSend($user, $cb, $text, $markup) : $this->say($user, $text, $markup);
    }

    /** 👥 دعوت دوستان */
    public function invite(array $user): void
    {
        if (!$user['profile_completed']) {
            $this->say($user, '📝 اول پروفایلت رو کامل کن، بعد لینک دعوتت آماده می‌شه.', Ui::onboardingKeyboard());
            return;
        }
        $refs = $this->app->referrals();
        $id = (int) $user['id'];
        $link = $refs->link($user);
        $reward = $this->app->settings->int('referral_reward_credits');
        $needed = $this->app->settings->int('required_referrals');
        $q = $this->app->quota()->status($id);
        $this->say($user, "👥 دوستات رو به هم‌صحبت دعوت کن!\n\n🔗 لینک اختصاصی تو:\n{$link}\n\n"
            . "🎁 هر دوستی که با این لینک بیاد و پروفایلش رو کامل کنه:\n"
            . ($needed > 0 ? '• ' . ($needed === 1 ? 'اولین دعوت' : Text::fa($needed) . ' دعوت اول') . "، چت ناشناس رو برات باز می‌کنه\n" : '')
            . ($reward > 0 ? '• ' . Text::fa($reward) . " چت اضافه می‌گیری (بعد از تموم شدن چت‌های رایگان روزانه)\n" : '')
            . "\n📊 دعوت موفق: " . Text::fa($refs->qualifiedCount($id)) . ' | در انتظار: ' . Text::fa($refs->pendingCount($id))
            . "\n🎁 اعتبار چت اضافه: " . Text::fa($q['credits']), $this->keyboardFor($user));
        // A ready-made message to forward to friends.
        $this->say($user, "سلام! 👋\nبا «هم‌صحبت» توی بله می‌تونی ناشناس با آدم‌های جدید گپ بزنی و دوست پیدا کنی 😍\n\nاز این لینک بیا:\n{$link}");
    }

    public function onCallback(array $user, string $data, array $cb): string|array|null
    {
        if ($data === 'inv') {
            $this->invite($user);
            return null;
        }
        if ($data === 'rq') {
            if (!$user['profile_completed']) {
                $this->app->handler(ProfileHandler::class)->begin($user);
                return null;
            }
            $this->qualify($user);
            $user = $this->fresh($user);
            $req = $this->app->requirements()->status($user, true);
            if ($req['ok']) {
                $this->ready($user, $cb);
                return '✅';
            }
            $this->requirementsScreen($user, $req, $cb);
            return ['text' => '⏳ هنوز همه مراحل انجام نشده.', 'alert' => false];
        }
        return null;
    }
}
