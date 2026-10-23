<?php
// Everything the admin panel can change. index.php checks the login and the CSRF token
// first; every action is written to {admin_log}. Returns [ok, Persian message].
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class PanelActions
{
    public function __construct(private App $app, private PanelAuth $auth)
    {
    }

    /** @return array{0:bool,1:string} */
    public function run(string $action, array $in): array
    {
        $uid = (int) ($in['user_id'] ?? 0);
        $user = $uid ? $this->app->users()->get($uid) : null;
        $needsUser = ['credit', 'unlimited', 'unlock', 'reset_today', 'suspend', 'unsuspend', 'end_user_chat', 'message', 'nickname', 'clear', 'fetch_account'];
        if (in_array($action, $needsUser, true) && !$user) {
            return [false, 'کاربر پیدا نشد.'];
        }
        $result = match ($action) {
            'credit'        => $this->credit($user, (int) ($in['amount'] ?? 0), !empty($in['notify'])),
            'unlimited'     => $this->unlimited($user, (int) ($in['days'] ?? -1), !empty($in['notify'])),
            'unlock'        => $this->unlock($user, !empty($in['on'])),
            'reset_today'   => $this->resetToday($user),
            'suspend'       => $this->suspend($user, max(0, (int) ($in['hours'] ?? 0))),
            'unsuspend'     => $this->unsuspend($user),
            'end_user_chat' => $this->endChat((int) ($this->app->matching()->activeChat($uid)['chat_id'] ?? 0)),
            'end_chat'      => $this->endChat((int) ($in['chat_id'] ?? 0)),
            'message'       => $this->message($user, (string) ($in['text'] ?? '')),
            'nickname'      => $this->nickname($user, (string) ($in['nickname'] ?? '')),
            'clear'         => $this->clear($user, (string) ($in['field'] ?? '')),
            'fetch_account' => $this->fetchAccount($user),
            'report'        => $this->report((int) ($in['report_id'] ?? 0), (string) ($in['status'] ?? '')),
            'settings'      => $this->settings($in),
            'cleanup'       => $this->cleanup(),
            'webhook'       => $this->webhook(),
            default         => [false, 'کار ناشناخته.'],
        };
        $this->auth->audit($action, $user ? '#' . $user['id'] . ' ' . ($user['nickname'] ?? '') : (isset($in['chat_id']) ? 'chat #' . (int) $in['chat_id'] : (isset($in['report_id']) ? 'report #' . (int) $in['report_id'] : null)), ($result[0] ? '✔ ' : '✘ ') . $result[1]);
        return $result;
    }

    private function notify(array $user, string $text, bool $main = false): void
    {
        $this->app->bale->send((int) $user['bale_user_id'], $text, $main ? Ui::mainKeyboard() : null);
    }

    private function credit(array $user, int $amount, bool $notify): array
    {
        if ($amount < 1 || $amount > 100000) {
            return [false, 'تعداد اعتبار باید بین ۱ تا ۱۰۰٬۰۰۰ باشد.'];
        }
        $this->app->quota()->grant((int) $user['id'], $amount, 'admin', 'panel-' . bin2hex(random_bytes(6)));
        if ($notify) {
            $this->notify($user, '🎁 ' . Text::fa($amount) . " چت اضافه هدیه گرفتی!\nبرو «" . Ui::FIND . '» رو بزن 😍');
        }
        return [true, Html::n($amount) . ' اعتبار به ' . $user['nickname'] . ' داده شد.'];
    }

    /** $days: > 0 = that many days, 0 = forever, < 0 = remove. */
    private function unlimited(array $user, int $days, bool $notify): array
    {
        if ($days < 0) {
            $this->app->quota()->setUnlimited((int) $user['id'], false);
            return [true, 'چت نامحدود ' . $user['nickname'] . ' برداشته شد.'];
        }
        $until = $days > 0 ? $this->app->now() + 86400 * min($days, 3650) : null;
        $this->app->quota()->setUnlimited((int) $user['id'], true, $until, 'panel');
        if ($notify) {
            $this->notify($user, '💎 چت نامحدود برات فعال شد' . ($days > 0 ? ' (' . Text::fa($days) . ' روز)' : '') . "! ✨\nهر چقدر خواستی هم‌صحبت پیدا کن.");
        }
        return [true, 'چت نامحدود برای ' . $user['nickname'] . ($days > 0 ? ' تا ' . Html::n($days) . ' روز' : ' (همیشگی)') . ' فعال شد.'];
    }

    private function unlock(array $user, bool $on): array
    {
        $this->app->users()->update((int) $user['id'], ['referral_waived' => $on ? 1 : 0]);
        if ($on) {
            $this->notify($user, '🔓 چت ناشناس برات باز شد! دیگه لازم نیست کسی رو دعوت کنی.');
        }
        return [true, $user['nickname'] . ($on ? ' بدون دعوت هم می‌تواند چت کند.' : ' دوباره به دعوت نیاز دارد.')];
    }

    private function resetToday(array $user): array
    {
        $this->app->quota()->resetToday((int) $user['id']);
        return [true, 'چت‌های رایگان امروز ' . $user['nickname'] . ' دوباره پر شد.'];
    }

    private function suspend(array $user, int $hours): array
    {
        if ($this->app->isAdmin((int) $user['bale_user_id'])) {
            return [false, 'ادمین را نمی‌شود تعلیق کرد.'];
        }
        $this->app->moderation()->suspend((int) $user['id'], $hours);
        return [true, $user['nickname'] . ' ' . ($hours ? Html::n($hours) . ' ساعت' : 'دائم') . ' تعلیق شد.'];
    }

    private function unsuspend(array $user): array
    {
        if (!$this->app->moderation()->unsuspend((int) $user['id'])) {
            return [false, 'این کاربر تعلیق نبود.'];
        }
        $this->notify($user, '✅ حسابت دوباره فعال شد. لطفاً قوانین رو رعایت کن 🙏', true);
        return [true, $user['nickname'] . ' آزاد شد.'];
    }

    private function endChat(int $chatId): array
    {
        $users = $chatId ? $this->app->matching()->endById($chatId, 'admin') : [];
        if (!$users) {
            return [false, 'چت فعالی پیدا نشد.'];
        }
        foreach ($users as $id) {
            if ($u = $this->app->users()->get($id)) {
                $this->notify($u, '🏁 این چت توسط مدیریت تموم شد.', true);
            }
        }
        return [true, 'چت #' . Html::n($chatId) . ' تموم شد.'];
    }

    private function message(array $user, string $text): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 3000) {
            return [false, 'متن پیام خالی یا خیلی طولانی است.'];
        }
        $res = $this->app->bale->send((int) $user['bale_user_id'], "📢 پیام از طرف مدیریت هم‌صحبت:\n\n" . $text);
        return !empty($res['ok']) ? [true, 'پیام برای ' . $user['nickname'] . ' فرستاده شد.'] : [false, 'ارسال نشد: ' . ($res['description'] ?? '?')];
    }

    private function nickname(array $user, string $nickname): array
    {
        [$value, $error] = Text::nickname($nickname);
        if ($error) {
            return [false, $error];
        }
        if (!$this->app->users()->nicknameFree($value, (int) $user['id'])) {
            return [false, 'این لقب مال کس دیگری است.'];
        }
        $this->app->users()->update((int) $user['id'], ['nickname' => $value]);
        return [true, 'لقب به ' . $value . ' تغییر کرد.'];
    }

    private function clear(array $user, string $field): array
    {
        $col = ['photo' => 'photo_file_id', 'bio' => 'bio', 'city' => 'city'][$field] ?? null;
        if (!$col) {
            return [false, 'بخش نامعتبر.'];
        }
        $this->app->users()->update((int) $user['id'], [$col => null]);
        return [true, ['photo' => 'عکس', 'bio' => 'درباره من', 'city' => 'شهر'][$field] . ' پاک شد.'];
    }

    /** Asks Bale (getChat) for the user's current name and @username. */
    private function fetchAccount(array $user): array
    {
        if (!$this->app->db->hasTable('user_accounts')) {
            return [false, 'اول migrate.php را اجرا کنید.'];
        }
        $res = $this->app->bale->getChat((int) $user['bale_user_id']);
        if (empty($res['ok']) || !is_array($res['result'] ?? null)) {
            return [false, 'بله اطلاعات را نداد: ' . ($res['description'] ?? '?')];
        }
        $this->app->users()->saveAccount((int) $user['id'], $res['result'], 'getChat');
        return [true, 'حساب بله به‌روز شد: ' . (Users::accountLabel($this->app->users()->accounts([(int) $user['id']])[(int) $user['id']] ?? []) ?: 'بدون نام و آیدی')];
    }

    private function report(int $id, string $status): array
    {
        if (!in_array($status, ['resolved', 'dismissed', 'open'], true)) {
            return [false, 'وضعیت نامعتبر.'];
        }
        $n = $this->app->db->run('UPDATE {reports} SET status = ?, reviewed_at = ? WHERE id = ?', [$status, $this->app->ts(), $id])->rowCount();
        return $n ? [true, 'گزارش #' . Html::n($id) . ' ' . ['resolved' => 'بسته شد.', 'dismissed' => 'رد شد.', 'open' => 'دوباره باز شد.'][$status]] : [false, 'گزارش پیدا نشد.'];
    }

    /** Saves every changed setting; checkboxes that are missing mean "off". */
    private function settings(array $in): array
    {
        $s = $this->app->settings;
        $values = (array) ($in['s'] ?? []);
        $changed = [];
        $errors = [];
        foreach (Settings::DEFAULTS as $name => [$default, $type]) {
            if ($type === 'bool') {
                $new = !empty($values[$name]) ? '1' : '0';
            } elseif (array_key_exists($name, $values)) {
                $new = is_array($values[$name])
                    ? implode(',', array_unique(array_filter(array_map(fn ($v) => preg_replace('~[^a-z_]~', '', (string) $v), $values[$name]))))
                    : trim(Text::digits((string) $values[$name]));
            } else {
                continue;
            }
            if ((string) $s->get($name) === $new) {
                continue;
            }
            if ($error = $s->set($name, $new, $this->app->now())) {
                $errors[] = $name . ': ' . $error;
            } else {
                $changed[] = $name;
            }
        }
        if ($errors) {
            return [false, implode(' | ', $errors) . ($changed ? ' — بقیه ذخیره شد: ' . implode('، ', $changed) : '')];
        }
        return [true, $changed ? 'ذخیره شد: ' . implode('، ', $changed) : 'چیزی تغییر نکرده بود.'];
    }

    private function cleanup(): array
    {
        $r = $this->app->maintenance()->run();
        return [true, 'پاک‌سازی انجام شد: ' . Html::n($r['expired_searches']) . ' جست‌وجوی منقضی، ' . Html::n($r['idle_chats']) . ' چت بی‌فعالیت، ' . Html::n(array_sum($r['purged'])) . ' ردیف قدیمی.'];
    }

    /** Points the bot at the folder this panel lives in (…/hamsobat/webhook.php). */
    private function webhook(): array
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/panel/index.php')), '/');
        $url = $base . '/webhook.php?k=' . rawurlencode((string) $this->app->config['bale']['webhook_secret']);
        $r = $this->app->bale->call('setWebhook', ['url' => $url]);
        return !empty($r['ok']) ? [true, 'وب‌هوک روی ' . $base . '/webhook.php تنظیم شد.'] : [false, 'خطای بله: ' . ($r['description'] ?? '?')];
    }
}
