<?php
// 🔎 Find a partner: checks → queue or match → tell both users.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class MatchingHandler extends Handler
{
    public const SEARCHING = '🔎 داریم برات هم‌صحبت پیدا می‌کنیم...';

    public function find(array $user, int $attempt = 1): void
    {
        $app = $this->app;
        $id = (int) $user['id'];
        if ($app->matching()->activeChat($id)) {
            $this->say($user, '💬 تو الان در یک چت هستی. اول «' . Ui::END . '» یا «' . Ui::NEXT . '» رو بزن.', Ui::chatKeyboard());
            return;
        }
        if (!$user['profile_completed'] || Users::missing($user)) {
            $this->say($user, '📝 اول پروفایلت رو کامل کن، بعد می‌تونی هم‌صحبت پیدا کنی.', Ui::onboardingKeyboard());
            return;
        }
        if ($attempt === 1 && !$app->rate()->allow($id, 'search', $app->settings->int('search_rate_per_minute'), 60)) {
            $this->say($user, '⏳ کمی صبر کن و چند لحظه دیگه دوباره امتحان کن.');
            return;
        }
        $app->handler(ReferralHandler::class)->qualify($user); // their own pending referral, if any
        $req = $app->requirements()->status($user);
        if (!$req['ok']) {
            $app->handler(ReferralHandler::class)->requirementsScreen($user, $req);
            return;
        }
        if (!$user['preferred_gender']) {
            $this->askGender($user);
            return;
        }
        if (!$app->quota()->hasAvailable($id)) {
            $this->outOfQuota($user);
            return;
        }

        $r = $app->matching()->search($user);
        foreach ($r['dropped'] ?? [] as $dropped) {
            $d = $app->users()->get($dropped);
            if ($d) {
                $this->outOfQuota($d);
            }
        }
        switch ($r['status']) {
            case 'matched':
                if (!$this->announce($r['chat_id']) && $attempt < 3) {
                    $this->find($this->fresh($user), $attempt + 1); // partner was unreachable: try the next one
                }
                return;
            case 'queued':
                $this->say($user, self::SEARCHING, Ui::searchingKeyboard());
                return;
            case 'renewed':
                $this->say($user, '🔎 هنوز داریم می‌گردیم... صبور باش 🙂', Ui::searchingKeyboard());
                return;
            case 'no_credits':
                $this->outOfQuota($user);
                return;
            case 'in_chat':
                $this->say($user, '💬 تو الان در یک چت هستی.', Ui::chatKeyboard());
                return;
            default:
                $this->say($user, '📝 اول پروفایلت رو کامل کن.', Ui::onboardingKeyboard());
        }
    }

    /**
     * Tells both users about a new chat. The partner who waited is told first; if they
     * cannot be reached (blocked the bot), the chat is closed and both get their chat back.
     * @return bool false when the waiting partner was gone, so the searcher should try again
     */
    public function announce(int $chatId): bool
    {
        $app = $this->app;
        $chat = $app->db->row('SELECT * FROM {chats} WHERE id = ?', [$chatId]);
        $one = $app->users()->get((int) $chat['user_one_id']);
        $two = $app->users()->get((int) $chat['user_two_id']);
        foreach ([[$one, $two], [$two, $one]] as $i => [$to, $other]) {
            $res = $this->sendMatch($to, $other, $chatId);
            if (!empty($res['ok'])) {
                $this->say($to, "💬 حالا سلام کن! هر چی بفرستی (متن، عکس، ویس) ناشناس به هم‌صحبتت می‌رسه.", Ui::chatKeyboard());
                continue;
            }
            if (!BaleApi::isUnreachable($res)) {
                $app->log->warning('match notice failed', ['chat' => $chatId, 'description' => $res['description'] ?? '']);
                $this->say($to, "💬 به یک هم‌صحبت وصل شدی! سلام کن 👋", Ui::chatKeyboard());
                continue;
            }
            // $to left the bot: undo the chat and give both their chat back.
            $app->matching()->endById($chatId, 'unreachable');
            $app->quota()->refund((int) $one['id'], $chatId, (string) $chat['user_one_credit'], $chat['usage_date']);
            $app->quota()->refund((int) $two['id'], $chatId, (string) $chat['user_two_credit'], $chat['usage_date']);
            if ($i === 1) {
                $this->say($one, '😕 هم‌صحبتت از ربات خارج شد و چت تموم شد.', Ui::mainKeyboard());
                return true; // the searcher left: nobody to search for
            }
            return false; // the waiting partner left: the searcher tries again
        }
        return true;
    }

    private function sendMatch(array $to, array $other, int $chatId): array
    {
        $card = Ui::card($other, $this->app->today(), [], false);
        $text = "🎉 هم‌صحبت پیدا شد!\n\n{$card}\n\nچی کار کنیم؟";
        $markup = Ui::inline([
            [['👤 دیدن پروفایل', "ch:pv:{$chatId}"], ['➡️ نفر بعدی', "ch:nx:{$chatId}"]],
            [['🚫 مسدود کردن', "ch:bl:{$chatId}"], ['🏠 پایان چت', "ch:end:{$chatId}"]],
            [['🚩 گزارش', "ch:rp:{$chatId}"]],
        ]);
        if ($other['photo_file_id'] && $other['show_photo']) {
            $res = $this->app->bale->sendMedia('photo', (int) $to['bale_user_id'], $other['photo_file_id'], $text, $markup);
            if (!empty($res['ok']) || BaleApi::isUnreachable($res)) {
                return $res;
            }
        }
        return $this->app->bale->send((int) $to['bale_user_id'], $text, $markup);
    }

    /** ❌ لغو جست‌وجو */
    public function cancel(array $user): void
    {
        if ($this->app->matching()->cancel((int) $user['id'])) {
            $this->say($user, '❌ جست‌وجو لغو شد. از سهمیه‌ات چیزی کم نشد.', Ui::mainKeyboard());
            return;
        }
        if ($this->app->matching()->activeChat((int) $user['id'])) {
            $this->say($user, '💬 همین الان به یک هم‌صحبت وصل شدی!', Ui::chatKeyboard());
            return;
        }
        $this->home($user, 'جست‌وجویی در جریان نبود.');
    }

    /** Text sent while searching. */
    public function whileSearching(array $user): void
    {
        $this->say($user, "⏳ هنوز داریم دنبال هم‌صحبت می‌گردیم...\nبرای لغو «" . Ui::CANCEL_SEARCH . '» رو بزن.', Ui::searchingKeyboard());
    }

    public function outOfQuota(array $user): void
    {
        $this->say($user, "سهمیه رایگان امروزت تموم شده 😅\nیک دوست رو دعوت کن تا بتونی چت‌های بیشتری داشته باشی.", Ui::inline([
            [['👥 دعوت دوست', 'inv']],
            [['👤 پروفایل من', 'pf:show'], ['🏠 منوی اصلی', 'home']],
        ]));
    }

    private function askGender(array $user): void
    {
        $this->say($user, '🎯 دوست داری با کی هم‌صحبت بشی؟', Ui::inline([
            [['👩 دختر', 'fg:f'], ['👨 پسر', 'fg:m']],
            [['🎲 فرقی نمی‌کنه', 'fg:any'], ['❌ لغو', 'fg:x']],
        ]));
    }

    public function onCallback(array $user, string $data, array $cb): string|array|null
    {
        if ($data === 'fd') {
            $this->find($user);
            return null;
        }
        if ($data === 'sc') {
            $this->cancel($user);
            return null;
        }
        if (preg_match('~^fg:(m|f|any|x)$~', $data, $m)) {
            if ($m[1] === 'x') {
                $this->home($user, 'باشه 👌');
                return null;
            }
            $this->app->users()->update((int) $user['id'], ['preferred_gender' => $m[1]]);
            $this->find($this->fresh($user));
            return '✅ ذخیره شد';
        }
        return null;
    }
}
