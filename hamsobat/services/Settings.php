<?php
// App settings: code default < config.php 'settings' < value saved by the admin (/set).
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Settings
{
    /** name => [default, type, description for the admin] */
    public const DEFAULTS = [
        'required_channel'            => ['', 'str', 'کانال اجباری (@name یا آیدی عددی). خالی = بدون کانال'],
        'channel_link'                => ['', 'str', 'لینک عضویت کانال (https://ble.ir/…)'],
        'require_channel'             => [1, 'bool', 'عضویت در کانال برای چت لازم است'],
        'channel_check_fail_open'     => [0, 'bool', 'اگر بررسی عضویت خطا داد، اجازه بده (۱) یا نه (۰)'],
        'required_referrals'          => [1, 'int', 'تعداد دعوت موفق لازم برای باز شدن چت (۰ = خاموش)'],
        'referral_reward_credits'     => [3, 'int', 'اعتبار چت اضافه برای هر دعوت موفق'],
        'referral_requires_channel'   => [1, 'bool', 'دعوت فقط وقتی حساب است که دوست عضو کانال باشد'],
        'referral_daily_reward_cap'   => [20, 'int', 'حداکثر دعوت‌های جایزه‌دار هر نفر در روز'],
        'referral_attach_minutes'     => [60, 'int', 'حساب تا چند دقیقه بعد از ساخت «جدید» حساب می‌شود'],
        'daily_free_chats'            => [5, 'int', 'چت رایگان روزانه (فقط چت‌های وصل‌شده حساب می‌شوند)'],
        'search_timeout_seconds'      => [30, 'int', 'زمان جست‌وجو (ثانیه)'],
        'rematch_cooldown_minutes'    => [10, 'int', 'دو نفر تا چند دقیقه دوباره به هم وصل نشوند'],
        'chat_idle_minutes'           => [30, 'int', 'پایان خودکار چت بی‌پیام (دقیقه، ۰ = خاموش)'],
        'min_age'                     => [18, 'int', 'حداقل سن. زیر ۱۸ و بالای ۱۸ هیچ‌وقت به هم وصل نمی‌شوند'],
        'max_age'                     => [80, 'int', 'حداکثر سن'],
        'profile_view_notify_minutes' => [60, 'int', 'فاصله اعلان «یکی پروفایلت رو دید» برای یک نفر (دقیقه)'],
        'message_retention_days'      => [7, 'int', 'نگهداری پیام‌های چت برای بررسی گزارش (روز)'],
        'store_message_text'          => [1, 'bool', 'ذخیره متن پیام‌ها برای بررسی گزارش'],
        'block_links'                 => [1, 'bool', 'جلوگیری از ارسال لینک، آیدی و شماره'],
        'allowed_types'               => ['text,photo,voice,audio,sticker,video,animation', 'str', 'نوع پیام‌های مجاز در چت'],
        'max_file_mb'                 => [20, 'int', 'حداکثر حجم فایل (مگابایت)'],
        'max_voice_seconds'           => [300, 'int', 'حداکثر طول ویس (ثانیه)'],
        'msg_rate_per_minute'         => [30, 'int', 'حداکثر پیام در دقیقه'],
        'search_rate_per_minute'      => [12, 'int', 'حداکثر جست‌وجو در دقیقه'],
        'inbox_rate_per_hour'         => [10, 'int', 'حداکثر پیام ناشناس در ساعت'],
        'report_rate_per_day'         => [10, 'int', 'حداکثر گزارش در روز'],
        'auto_suspend_reports'        => [3, 'int', 'تعلیق خودکار ۲۴ ساعته بعد از این تعداد گزارش‌دهنده در ۲۴ ساعت (۰ = خاموش)'],
        'notify_admin_reports'        => [1, 'bool', 'هر گزارش برای ادمین فرستاده شود'],
        'feature_inbox'               => [1, 'bool', 'صندوق پیام ناشناس'],
        'feature_media'               => [1, 'bool', 'ارسال عکس/ویس/صدا در چت'],
        'feature_profile_view_notify' => [1, 'bool', 'اعلان بازدید پروفایل'],
        'maintenance'                 => [0, 'bool', 'حالت تعمیر: ربات فقط به ادمین جواب می‌دهد'],
    ];

    private ?array $saved = null;

    public function __construct(private Database $db, private array $overrides = [])
    {
    }

    public function get(string $name): int|string
    {
        if (!isset(self::DEFAULTS[$name])) {
            throw new \InvalidArgumentException("unknown setting {$name}");
        }
        [$default, $type] = self::DEFAULTS[$name];
        $value = $this->saved()[$name] ?? $this->overrides[$name] ?? $default;
        return $type === 'str' ? trim((string) $value) : (int) $value;
    }

    public function int(string $name): int
    {
        return (int) $this->get($name);
    }

    public function bool(string $name): bool
    {
        return (int) $this->get($name) !== 0;
    }

    public function str(string $name): string
    {
        return (string) $this->get($name);
    }

    /** Validates and saves. Returns null on success or a Persian error. */
    public function set(string $name, string $value, int $now): ?string
    {
        if (!isset(self::DEFAULTS[$name])) {
            return '❌ چنین تنظیمی وجود ندارد. فهرست: /settings';
        }
        $type = self::DEFAULTS[$name][1];
        $value = trim($value);
        if ($type === 'int' && !preg_match('~^\d{1,7}$~', $value)) {
            return '❌ مقدار باید عدد باشد.';
        }
        if ($type === 'bool') {
            $map = ['1' => '1', 'on' => '1', 'true' => '1', '0' => '0', 'off' => '0', 'false' => '0'];
            if (!isset($map[strtolower($value)])) {
                return '❌ مقدار باید on یا off باشد.';
            }
            $value = $map[strtolower($value)];
        }
        if ($name === 'required_channel' && $value !== '' && !preg_match('~^(@[A-Za-z0-9_]{3,64}|-?\d{3,20})$~', $value)) {
            return '❌ کانال را به شکل ‎@name‎ یا آیدی عددی بنویسید.';
        }
        if ($name === 'channel_link' && $value !== '' && !preg_match('~^https://\S+$~', $value)) {
            return '❌ لینک باید با https:// شروع شود.';
        }
        $this->internal($name, $value, $now);
        return null;
    }

    /** Internal key/value (names starting with _), e.g. the last cleanup time. */
    public function raw(string $name): ?string
    {
        $v = $this->saved()[$name] ?? null;
        return $v === null ? null : (string) $v;
    }

    public function internal(string $name, string $value, int $now): void
    {
        $this->db->run(
            'INSERT INTO {settings} (name, value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)',
            [$name, $value, gmdate('Y-m-d H:i:s', $now)]
        );
        $this->saved = null;
    }

    /** Atomically claims a periodic job: true for exactly one request per $every seconds. */
    public function claim(string $name, int $every, int $now): bool
    {
        $last = (int) ($this->raw($name) ?? 0);
        if ($now - $last < $every) {
            return false;
        }
        $stamp = gmdate('Y-m-d H:i:s', $now);
        if ($this->raw($name) === null) {
            try {
                $this->db->run('INSERT INTO {settings} (name, value, updated_at) VALUES (?, ?, ?)', [$name, (string) $now, $stamp]);
                $this->saved = null;
                return true;
            } catch (\PDOException $e) {
                if (!Database::isDuplicate($e)) {
                    throw $e;
                }
                $this->saved = null;
                return false;
            }
        }
        // Compare-and-set: only the request that still sees the old value wins.
        $won = $this->db->run('UPDATE {settings} SET value = ?, updated_at = ? WHERE name = ? AND value = ?', [(string) $now, $stamp, $name, (string) $last])->rowCount() === 1;
        $this->saved = null;
        return $won;
    }

    public function all(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $name => [$default, $type, $help]) {
            $out[$name] = ['value' => $this->get($name), 'type' => $type, 'help' => $help];
        }
        return $out;
    }

    private function saved(): array
    {
        if ($this->saved === null) {
            $this->saved = [];
            foreach ($this->db->all('SELECT name, value FROM {settings}') as $r) {
                $this->saved[$r['name']] = $r['value'];
            }
        }
        return $this->saved;
    }
}
