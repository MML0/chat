<?php
// Every button label and keyboard in one place. Reply-keyboard buttons arrive as plain
// text, so the handlers match them with Ui::label(), which ignores emoji variation
// selectors, half-spaces and spaces (different Bale clients send them differently).
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Ui
{
    // Main menu (persistent reply keyboard)
    public const FIND = '🔎 پیدا کردن هم‌صحبت';
    public const MY_PROFILE = '👤 پروفایل من';
    public const INBOX = '📥 پیام‌های ناشناس';
    public const INVITE = '👥 دعوت دوستان';
    public const SETTINGS = '⚙️ تنظیمات';
    // Before the profile is complete
    public const COMPLETE_PROFILE = '📝 تکمیل پروفایل';
    public const GUIDE = 'ℹ️ راهنمای هم‌صحبت';
    // While searching
    public const CANCEL_SEARCH = '❌ لغو جست‌وجو';
    // While chatting
    public const NEXT = '➡️ نفر بعدی';
    public const END = '🏁 پایان چت';
    public const VIEW_PROFILE = '👤 دیدن پروفایل';
    public const BLOCK = '🚫 مسدود کردن';
    public const REPORT = '🚩 گزارش';

    public const ALL = [
        self::FIND, self::MY_PROFILE, self::INBOX, self::INVITE, self::SETTINGS, self::COMPLETE_PROFILE, self::GUIDE,
        self::CANCEL_SEARCH, self::NEXT, self::END, self::VIEW_PROFILE, self::BLOCK, self::REPORT,
    ];

    /** The canonical label for a typed/pressed text, or null when it is not a button. */
    public static function label(string $text): ?string
    {
        static $map = null;
        if ($map === null) {
            foreach (self::ALL as $l) {
                $map[self::key($l)] = $l;
            }
        }
        return $text === '' || mb_strlen($text) > 40 ? null : ($map[self::key($text)] ?? null);
    }

    private static function key(string $s): string
    {
        return preg_replace('~[\x{FE0F}\x{200C}\x{200D}\s]~u', '', strtr($s, ['ي' => 'ی', 'ك' => 'ک']));
    }

    // ------------------------------------------------------------------ keyboards

    public static function reply(array $rows): array
    {
        // Only fields seen working with Bale. (is_persistent is Telegram-only so far: not sent.)
        return [
            'keyboard'        => array_map(fn ($row) => array_map(fn ($t) => ['text' => $t], $row), $rows),
            'resize_keyboard' => true,
        ];
    }

    public static function mainKeyboard(): array
    {
        return self::reply([[self::FIND], [self::MY_PROFILE, self::INBOX], [self::INVITE, self::SETTINGS]]);
    }

    public static function onboardingKeyboard(): array
    {
        return self::reply([[self::COMPLETE_PROFILE], [self::GUIDE]]);
    }

    public static function searchingKeyboard(): array
    {
        return self::reply([[self::CANCEL_SEARCH]]);
    }

    public static function chatKeyboard(): array
    {
        return self::reply([[self::NEXT, self::END], [self::VIEW_PROFILE, self::BLOCK, self::REPORT]]);
    }

    public static function removeKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }

    /**
     * Inline (glass) buttons. $rows = [[['label', 'callback:data'], ['label', 'url' => 'https://…']], …]
     */
    public static function inline(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $btns = [];
            foreach ($row as $b) {
                $btns[] = isset($b['url']) ? ['text' => $b[0], 'url' => $b['url']] : ['text' => $b[0], 'callback_data' => $b[1]];
            }
            $out[] = $btns;
        }
        return ['inline_keyboard' => $out];
    }

    // ------------------------------------------------------------------ texts

    public static function genderName(?string $g): string
    {
        return ['m' => '👨 پسر', 'f' => '👩 دختر', 'any' => '🎲 فرقی نمی‌کنه'][$g ?? ''] ?? '—';
    }

    /**
     * Public profile text. Never contains the Bale id, the exact birth date or anything
     * the owner hid in the privacy settings. $full adds display name and interests.
     */
    public static function card(array $u, string $today, array $interests, bool $full, bool $owner = false): string
    {
        $lines = ['👤 لقب: ' . $u['nickname']];
        if ($full) {
            $lines[] = '🙂 اسم: ' . $u['display_name'];
            $lines[] = '⚧ جنسیت: ' . self::genderName($u['gender']);
        }
        $lines[] = '🎂 سن: ' . Text::fa(Text::age($u['birth_date'], $today));
        if ($owner) {
            $lines[] = '📅 تاریخ تولد: ' . Text::jalaliDate($u['birth_date']) . ' (فقط خودت می‌بینی)';
        }
        if (!empty($u['city']) && ($owner || $u['show_city'])) {
            $lines[] = '📍 شهر: ' . $u['city'] . ($owner && !$u['show_city'] ? ' (مخفی)' : '');
        }
        if (!empty($u['bio']) && ($owner || $u['show_bio'])) {
            $lines[] = '📝 درباره: ' . $u['bio'] . ($owner && !$u['show_bio'] ? ' (مخفی)' : '');
        }
        if ($full && $interests) {
            $lines[] = '🎯 علاقه‌مندی‌ها: ' . implode('، ', $interests);
        }
        return implode("\n", $lines);
    }

    public static function guide(int $freeChats, int $reward, int $retentionDays = 7): string
    {
        return "ℹ️ راهنمای هم‌صحبت\n\n"
            . "🔎 «پیدا کردن هم‌صحبت»: به یک آدم جدید به‌صورت ناشناس وصل می‌شی. هر چی بفرستی (متن، عکس، ویس) فقط از طریق ربات به اون می‌رسه و اسم و آیدی‌ات معلوم نمی‌شه.\n\n"
            . "➡️ «نفر بعدی»: چت فعلی تموم می‌شه و دنبال نفر جدید می‌گردیم.\n"
            . "🏁 «پایان چت»: چت تموم می‌شه.\n"
            . "🚫 «مسدود کردن»: دیگه هیچ‌وقت به هم وصل نمی‌شید.\n"
            . "🚩 «گزارش»: اگه کسی اذیت کرد، گزارش بده تا بررسی کنیم.\n\n"
            . '🎁 هر روز ' . Text::fa($freeChats) . " چت رایگان داری (فقط چت‌هایی که وصل بشن حساب می‌شن). با دعوت هر دوست، " . Text::fa($reward) . " چت اضافه می‌گیری.\n\n"
            . "📥 «پیام‌های ناشناس»: لینک اختصاصیت رو هر جا بذاری، بقیه می‌تونن ناشناس برات پیام بفرستن.\n\n"
            . "🛡 قوانین: احترام بذار، محتوای غیراخلاقی نفرست، لینک و شماره و آیدی نده.\n"
            . '🔐 هم‌صحبت‌ها اسم و آیدی بله‌ی هم رو نمی‌بینن. مدیر هم‌صحبت برای امنیت، نام و آیدی حساب بله‌ات و پیام‌ها رو می‌بینه؛ پیام‌ها بعد از ' . Text::fa($retentionDays) . ' روز پاک می‌شن.';
    }
}
