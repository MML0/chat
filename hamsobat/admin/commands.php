<?php
// Admin commands. Only Bale user ids listed in config.php → admin → ids reach this
// (checked on from.id in a private chat by the router). Users are named by internal id
// (#12 or 12) or by nickname.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class AdminHandler extends Handler
{
    private const HELP = "🛠 دستورات ادمین\n\n"
        . "/stats – آمار\n"
        . "/settings – همه تنظیمات\n"
        . "/set <نام> <مقدار> – تغییر تنظیم (مثال: /set daily_free_chats 7)\n"
        . "/setchannel <@کانال> <لینک> – کانال اجباری (/setchannel off = خاموش)\n"
        . "/feature <inbox|media|profile_view_notify> <on|off>\n"
        . "/maintenance <on|off> – حالت تعمیر\n"
        . "/reports – گزارش‌های باز\n"
        . "/report <شماره> – جزئیات گزارش\n"
        . "/user <آیدی یا لقب> – اطلاعات کاربر\n"
        . "/suspend <آیدی یا لقب> [ساعت] – تعلیق (بدون ساعت = دائم)\n"
        . "/unsuspend <آیدی یا لقب>\n"
        . "/credit <آیدی یا لقب> <تعداد> – اعتبار چت اضافه\n"
        . "/unlock <آیدی یا لقب> – بدون نیاز به دعوت\n"
        . "/errors – خطاهای اخیر\n"
        . "/cleanup – اجرای پاک‌سازی و پایان جست‌وجوهای منقضی";

    /** @return bool false when $text is not an admin command (it is then handled normally). */
    public function command(array $admin, string $text): bool
    {
        $parts = preg_split('~\s+~', trim($text));
        $cmd = strtolower(preg_replace('~@\w+$~', '', $parts[0]));
        $args = array_slice($parts, 1);
        $app = $this->app;

        switch ($cmd) {
            case '/admin':
            case '/panel':
                $this->say($admin, self::HELP . "\n\n" . Maintenance::statsText($app->maintenance()->stats()));
                return true;

            case '/stats':
                $this->say($admin, Maintenance::statsText($app->maintenance()->stats()));
                return true;

            case '/settings':
                $lines = [];
                foreach ($app->settings->all() as $name => $s) {
                    $lines[] = "{$name} = " . ($s['value'] === '' ? '—' : $s['value']) . "\n   {$s['help']}";
                }
                $this->say($admin, "⚙️ تنظیمات (تغییر: /set نام مقدار)\n\n" . implode("\n", $lines));
                return true;

            case '/set':
                if (count($args) < 2) {
                    $this->say($admin, 'مثال: /set daily_free_chats 7');
                    return true;
                }
                $error = $app->settings->set($args[0], implode(' ', array_slice($args, 1)), $app->now());
                $this->say($admin, $error ?? "✅ {$args[0]} = " . $app->settings->get($args[0]));
                return true;

            case '/setchannel':
                if (($args[0] ?? '') === 'off') {
                    $app->settings->set('require_channel', '0', $app->now());
                    $this->say($admin, '✅ عضویت در کانال دیگر لازم نیست.');
                    return true;
                }
                if (!$args) {
                    $this->say($admin, 'مثال: /setchannel @hamsohbat_channel https://ble.ir/hamsohbat_channel');
                    return true;
                }
                $error = $app->settings->set('required_channel', $args[0], $app->now())
                    ?? (isset($args[1]) ? $app->settings->set('channel_link', $args[1], $app->now()) : null);
                if ($error) {
                    $this->say($admin, $error);
                    return true;
                }
                $app->settings->set('require_channel', '1', $app->now());
                $res = $app->bale->getChatMember($args[0], (int) $admin['bale_user_id']);
                $this->say($admin, "✅ کانال اجباری: {$args[0]}\n" . (!empty($res['ok'])
                    ? '✔ بررسی عضویت کار می‌کند (وضعیت شما: ' . ($res['result']['status'] ?? '?') . ').'
                    : '❌ بررسی عضویت خطا داد: ' . ($res['description'] ?? '?') . "\nربات را ادمین کانال کنید."));
                return true;

            case '/feature':
                $name = 'feature_' . ($args[0] ?? '');
                $error = isset(Settings::DEFAULTS[$name]) ? $app->settings->set($name, $args[1] ?? '', $app->now()) : '❌ inbox، media یا profile_view_notify';
                $this->say($admin, $error ?? "✅ {$name} = " . $app->settings->get($name));
                return true;

            case '/maintenance':
                $error = $app->settings->set('maintenance', $args[0] ?? '', $app->now());
                $this->say($admin, $error ?? ($app->settings->bool('maintenance') ? '🛠 حالت تعمیر روشن شد (فقط ادمین جواب می‌گیرد).' : '✅ حالت تعمیر خاموش شد.'));
                return true;

            case '/reports':
                $rows = $app->db->all("SELECT r.*, a.nickname AS reporter, b.nickname AS reported FROM {reports} r
                    JOIN {users} a ON a.id = r.reporter_user_id JOIN {users} b ON b.id = r.reported_user_id
                    WHERE r.status = 'open' ORDER BY r.id DESC LIMIT 15");
                if (!$rows) {
                    $this->say($admin, '✅ گزارش بازی نیست.');
                    return true;
                }
                $lines = array_map(fn ($r) => "#{$r['id']} | " . Moderation::REASONS[$r['reason']] . " | {$r['reporter']} → {$r['reported']} (#{$r['reported_user_id']}) | " . substr($r['created_at'], 0, 16), $rows);
                $this->say($admin, "🚩 گزارش‌های باز (جزئیات: /report شماره)\n\n" . implode("\n", $lines));
                return true;

            case '/report':
                $this->showReport($admin, (int) ($args[0] ?? 0));
                return true;

            case '/user':
                $u = $this->resolve($args[0] ?? '');
                if (!$u) {
                    $this->say($admin, '❌ کاربر پیدا نشد.');
                    return true;
                }
                $q = $app->quota()->status((int) $u['id']);
                $against = (int) $app->db->value('SELECT COUNT(*) FROM {reports} WHERE reported_user_id = ?', [$u['id']]);
                $this->say($admin, "👤 کاربر #{$u['id']}\nلقب: " . ($u['nickname'] ?? '—') . "\nاسم: " . ($u['display_name'] ?? '—')
                    . "\nآیدی بله: {$u['bale_user_id']}\nجنسیت: " . Ui::genderName($u['gender']) . ' | سن: ' . ($u['birth_date'] ? Text::age($u['birth_date'], $app->today()) : '—')
                    . "\nوضعیت: {$u['status']}" . ($u['suspended_until'] ? " تا {$u['suspended_until']} UTC" : '')
                    . "\nپروفایل کامل: " . ($u['profile_completed'] ? 'بله' : 'خیر') . ' | بدون دعوت: ' . ($u['referral_waived'] ? 'بله' : 'خیر')
                    . "\nدعوت موفق: " . $app->referrals()->qualifiedCount((int) $u['id']) . ' | دعوت‌کننده: ' . ($u['referred_by_user_id'] ? "#{$u['referred_by_user_id']}" : '—')
                    . "\nچت امروز: {$q['used']} | اعتبار: {$q['credits']}\nگزارش علیه او: {$against}\nعضویت: {$u['created_at']} UTC");
                return true;

            case '/suspend':
                $u = $this->resolve($args[0] ?? '');
                if (!$u) {
                    $this->say($admin, '❌ کاربر پیدا نشد. مثال: /suspend 12 24');
                    return true;
                }
                $hours = (int) ($args[1] ?? 0);
                $app->moderation()->suspend((int) $u['id'], $hours);
                $this->say($admin, "⛔ #{$u['id']} " . ($hours > 0 ? "{$hours} ساعت" : 'دائم') . ' تعلیق شد.');
                return true;

            case '/unsuspend':
                $u = $this->resolve($args[0] ?? '');
                $ok = $u && $app->moderation()->unsuspend((int) $u['id']);
                $this->say($admin, $ok ? "✅ #{$u['id']} آزاد شد." : '❌ کاربر پیدا نشد یا تعلیق نبود.');
                if ($ok) {
                    $app->bale->send((int) $u['bale_user_id'], '✅ حسابت دوباره فعال شد. لطفاً قوانین رو رعایت کن 🙏', Ui::mainKeyboard());
                }
                return true;

            case '/credit':
                $u = $this->resolve($args[0] ?? '');
                $n = (int) ($args[1] ?? 0);
                if (!$u || $n <= 0 || $n > 1000) {
                    $this->say($admin, 'مثال: /credit 12 5');
                    return true;
                }
                $app->quota()->grant((int) $u['id'], $n, 'admin', 'a' . $admin['id'] . '-' . bin2hex(random_bytes(6)));
                $app->bale->send((int) $u['bale_user_id'], '🎁 ' . Text::fa($n) . ' چت اضافه هدیه گرفتی!');
                $this->say($admin, "✅ {$n} اعتبار به #{$u['id']} داده شد.");
                return true;

            case '/unlock':
                $u = $this->resolve($args[0] ?? '');
                if (!$u) {
                    $this->say($admin, '❌ کاربر پیدا نشد.');
                    return true;
                }
                $app->users()->update((int) $u['id'], ['referral_waived' => 1]);
                $this->say($admin, "✅ #{$u['id']} بدون دعوت هم می‌تواند چت کند.");
                return true;

            case '/errors':
                $rows = $app->db->all('SELECT * FROM {error_logs} ORDER BY id DESC LIMIT 10');
                $lines = array_map(fn ($r) => substr($r['created_at'], 5, 11) . " [{$r['level']}] {$r['message']}" . ($r['context'] ? "\n   " . mb_substr($r['context'], 0, 300) : ''), $rows);
                $this->say($admin, $lines ? "⚠️ خطاهای اخیر (UTC)\n\n" . implode("\n", $lines) : '✅ خطایی ثبت نشده.');
                return true;

            case '/cleanup':
                $r = $app->maintenance()->run();
                $this->say($admin, "✅ انجام شد.\nجست‌وجوی منقضی: {$r['expired_searches']} | چت بی‌فعالیت: {$r['idle_chats']}\nپاک‌شده: " . json_encode($r['purged']));
                return true;
        }
        return false;
    }

    private function showReport(array $admin, int $id): void
    {
        $db = $this->app->db;
        $r = $db->row('SELECT * FROM {reports} WHERE id = ?', [$id]);
        if (!$r) {
            $this->say($admin, '❌ گزارش پیدا نشد.');
            return;
        }
        $reporter = $this->app->users()->get((int) $r['reporter_user_id']);
        $reported = $this->app->users()->get((int) $r['reported_user_id']);
        $text = "🚩 گزارش #{$r['id']} ({$r['status']})\nدلیل: " . Moderation::REASONS[$r['reason']] . ($r['details'] ? "\nتوضیح: {$r['details']}" : '')
            . "\nگزارش‌دهنده: #{$reporter['id']} ({$reporter['nickname']})\nگزارش‌شده: #{$reported['id']} ({$reported['nickname']}) – وضعیت: {$reported['status']}"
            . "\nکل گزارش‌ها علیه او: " . $db->value('SELECT COUNT(*) FROM {reports} WHERE reported_user_id = ?', [$reported['id']]);
        if ($r['chat_id']) {
            $msgs = $db->all('SELECT * FROM {messages} WHERE chat_id = ? ORDER BY id DESC LIMIT 15', [$r['chat_id']]);
            $text .= "\n\n💬 آخرین پیام‌های چت #{$r['chat_id']}:";
            foreach (array_reverse($msgs) as $m) {
                $who = (int) $m['sender_user_id'] === (int) $reported['id'] ? '🔴' : '🔵';
                $text .= "\n{$who} [{$m['message_type']}] " . ($m['text_content'] !== null ? mb_substr($m['text_content'], 0, 200) : '') . ($m['delivery_status'] !== 'sent' ? " ({$m['delivery_status']})" : '');
            }
            if (!$msgs) {
                $text .= "\n(پیامی ذخیره نشده)";
            }
            $text .= "\n🔴 = گزارش‌شده، 🔵 = گزارش‌دهنده";
        } elseif ($r['inbox_message_id']) {
            $m = $db->row('SELECT * FROM {anonymous_inbox} WHERE id = ?', [$r['inbox_message_id']]);
            $text .= "\n\n📩 پیام ناشناس: [" . ($m['message_type'] ?? '?') . '] ' . mb_substr((string) ($m['text_content'] ?? ''), 0, 500);
        }
        $this->say($admin, $text, Ui::inline([
            [['⛔ تعلیق ۲۴ ساعته', "ar:{$id}:s24"], ['⛔ تعلیق دائم', "ar:{$id}:sp"]],
            [['✅ بررسی شد', "ar:{$id}:ok"], ['❌ رد گزارش', "ar:{$id}:no"]],
        ]));
    }

    public function onCallback(array $admin, string $data, array $cb): string|array|null
    {
        if (!preg_match('~^ar:(\d{1,10}):(s24|sp|ok|no)$~', $data, $m)) {
            return null;
        }
        $r = $this->app->db->row('SELECT * FROM {reports} WHERE id = ?', [(int) $m[1]]);
        if (!$r) {
            return 'پیدا نشد';
        }
        if ($m[2] === 's24' || $m[2] === 'sp') {
            $this->app->moderation()->suspend((int) $r['reported_user_id'], $m[2] === 's24' ? 24 : 0);
        }
        $this->app->db->run('UPDATE {reports} SET status = ?, reviewed_by_user_id = ?, reviewed_at = ? WHERE id = ?', [
            $m[2] === 'no' ? 'dismissed' : 'resolved', $admin['id'], $this->app->ts(), $r['id'],
        ]);
        return ['s24' => '⛔ ۲۴ ساعت تعلیق شد', 'sp' => '⛔ دائم تعلیق شد', 'ok' => '✅ بسته شد', 'no' => '❌ رد شد'][$m[2]];
    }

    private function resolve(string $who): ?array
    {
        $who = ltrim(trim($who), '#@');
        if ($who === '') {
            return null;
        }
        return ctype_digit($who) ? $this->app->users()->get((int) $who) : $this->app->users()->byNickname($who);
    }
}
