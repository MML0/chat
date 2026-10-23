<?php
// /start (with referral or anonymous-message deep links), guide, main menu.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class StartHandler extends Handler
{
    public const WELCOME = "سلام! 👋 به هم‌صحبت خوش اومدی.\nاینجا می‌تونی با آدم‌های جدید به‌صورت ناشناس گپ بزنی، دوست پیدا کنی و گفت‌وگوهای جالب داشته باشی.";

    /**
     * Deep links (https://ble.ir/<bot>?start=<payload>) arrive as "/start <payload>":
     *   ref<8 chars>  → referral (credits the inviter only for a genuinely new account)
     *   msg<10 chars> → anonymous message to the link owner (never counts as a referral)
     */
    public function start(array $user, string $payload, bool $isNew): void
    {
        $app = $this->app;
        $app->users()->clearState((int) $user['id']);

        if (preg_match('~^ref([a-z2-9]{8})$~', $payload, $m)) {
            $inviter = $app->referrals()->attach($user, $m[1], $isNew);
            if ($inviter) {
                $app->bale->send((int) $inviter['bale_user_id'], "🎉 یک نفر با لینک دعوتت وارد هم‌صحبت شد!\nوقتی پروفایلش رو کامل کنه، دعوتت حساب می‌شه ✅");
                $user = $this->fresh($user);
            }
        } elseif (str_starts_with($payload, 'msg')) {
            if ($isNew) {
                $this->say($user, self::WELCOME, Ui::onboardingKeyboard());
            }
            if (preg_match('~^msg([a-z2-9]{10})$~', $payload, $m)) {
                $app->handler(InboxHandler::class)->openLink($user, $m[1]);
            } else {
                $this->say($user, '❌ این لینک پیام ناشناس معتبر نیست.', $this->keyboardFor($user));
            }
            return;
        }

        if ($app->matching()->activeChat((int) $user['id'])) {
            $this->say($user, '💬 تو الان در یک چت هستی. پیامت رو بفرست یا از دکمه‌های پایین استفاده کن.', Ui::chatKeyboard());
            return;
        }
        if ($app->matching()->isSearching((int) $user['id'])) {
            $this->say($user, '🔎 هنوز داریم برات هم‌صحبت پیدا می‌کنیم...', Ui::searchingKeyboard());
            return;
        }
        $this->say($user, self::WELCOME, $user['profile_completed'] ? Ui::mainKeyboard() : Ui::onboardingKeyboard());
    }

    public function guide(array $user): void
    {
        $s = $this->app->settings;
        $this->say($user, Ui::guide($s->int('daily_free_chats'), $s->int('referral_reward_credits')), $this->keyboardFor($user));
    }

    public function home(array $user, string $text = '🏠 منوی اصلی'): void
    {
        parent::home($user, $text);
    }

    public function notChatting(array $user): void
    {
        $this->home($user, '💬 الان در چتی نیستی. برای شروع «' . Ui::FIND . '» رو بزن.');
    }

    /** Any other text outside a chat. */
    public function fallback(array $user): void
    {
        if (!$user['profile_completed']) {
            $this->say($user, '👋 برای شروع، پروفایلت رو کامل کن 👇', Ui::onboardingKeyboard());
            return;
        }
        $this->say($user, '👇 از دکمه‌های پایین استفاده کن.', Ui::mainKeyboard());
    }

    public function onCallback(array $user, string $data, array $cb): string|array|null
    {
        if ($data === 'home') {
            $this->home($user);
        }
        return null;
    }
}
