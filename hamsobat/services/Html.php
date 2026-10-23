<?php
// Small view helpers for the admin panel: escaping, Persian numbers, Jalali dates,
// "5 minutes ago", badges. Every value printed in a view goes through e() (or a helper
// that calls it), so user text can never inject HTML.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Html
{
    private const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    private static ?\DateTimeZone $tz = null;

    public static function setTimezone(string $tz): void
    {
        self::$tz = new \DateTimeZone($tz);
    }

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Persian digits with thousands separators. */
    public static function n(int|float|string|null $n, int $decimals = 0): string
    {
        if ($n === null || $n === '') {
            return '—';
        }
        return Text::fa(str_replace(',', '٬', number_format((float) $n, $decimals)));
    }

    /** Unix time of a UTC DATETIME from the DB. */
    public static function time(?string $utc): ?int
    {
        return $utc ? strtotime($utc . ' UTC') : null;
    }

    private static function local(int $t): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $t))->setTimezone(self::$tz ?? new \DateTimeZone('Asia/Tehran'));
    }

    /** "۱۴۰۵/۰۱/۲۳ ۱۴:۰۵" in the app timezone. */
    public static function date(?string $utc, bool $withTime = true): string
    {
        $t = self::time($utc);
        if (!$t) {
            return '—';
        }
        $d = self::local($t);
        [$y, $m, $day] = Text::gregorianToJalali((int) $d->format('Y'), (int) $d->format('n'), (int) $d->format('j'));
        return Text::fa(sprintf('%04d/%02d/%02d', $y, $m, $day) . ($withTime ? ' ' . $d->format('H:i') : ''));
    }

    /** "۲۳ فروردین ۱۴۰۵" from a Gregorian Y-m-d (birth dates). */
    public static function dayName(?string $ymd): string
    {
        if (!$ymd) {
            return '—';
        }
        [$gy, $gm, $gd] = array_map('intval', explode('-', $ymd));
        [$y, $m, $d] = Text::gregorianToJalali($gy, $gm, $gd);
        return Text::fa($d) . ' ' . self::MONTHS[$m - 1] . ' ' . Text::fa($y);
    }

    public static function clock(?string $utc): string
    {
        $t = self::time($utc);
        return $t ? Text::fa(self::local($t)->format('H:i')) : '';
    }

    /** "۵ دقیقه پیش" */
    public static function ago(?string $utc, ?int $now = null): string
    {
        $t = self::time($utc);
        if (!$t) {
            return 'هرگز';
        }
        $s = max(0, ($now ?? time()) - $t);
        return match (true) {
            $s < 45      => 'همین الان',
            $s < 3600    => Text::fa(max(1, intdiv($s, 60))) . ' دقیقه پیش',
            $s < 86400   => Text::fa(intdiv($s, 3600)) . ' ساعت پیش',
            $s < 2592000 => Text::fa(intdiv($s, 86400)) . ' روز پیش',
            default      => self::date($utc, false),
        };
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60    => Text::fa($seconds) . ' ثانیه',
            $seconds < 3600  => Text::fa(intdiv($seconds, 60)) . ' دقیقه',
            $seconds < 86400 => Text::fa(intdiv($seconds, 3600)) . ' ساعت و ' . Text::fa(intdiv($seconds % 3600, 60)) . ' دقیقه',
            default          => Text::fa(intdiv($seconds, 86400)) . ' روز',
        };
    }

    public static function bytes(int|float $b): string
    {
        $u = ['بایت', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < 4) {
            $b /= 1024;
            $i++;
        }
        return self::n($b, $i ? 1 : 0) . ' ' . $u[$i];
    }

    public static function ms(float $ms): string
    {
        return $ms >= 1000 ? self::n($ms / 1000, 2) . ' ثانیه' : self::n($ms) . ' میلی‌ثانیه';
    }

    public static function badge(string $text, string $kind = ''): string
    {
        return '<span class="badge ' . self::e($kind) . '">' . self::e($text) . '</span>';
    }

    public static function gender(?string $g): string
    {
        return ['m' => '👨 پسر', 'f' => '👩 دختر', 'any' => '🎲 فرقی نمی‌کنه'][$g ?? ''] ?? '—';
    }

    /** Round avatar: the Bale profile photo through media.php, or a coloured initial. */
    public static function avatar(?array $u, string $size = '', bool $online = false): string
    {
        if ($online) {
            return '<span class="av-wrap">' . self::avatar($u, $size) . '<i class="online-dot" title="آنلاین"></i></span>';
        }
        if (!$u) {
            return '<span class="avatar ' . $size . '">?</span>';
        }
        $name = (string) ($u['nickname'] ?? $u['display_name'] ?? '?');
        $hue = crc32($name) % 360;
        $initial = self::e(mb_substr($name, 0, 1));
        $img = !empty($u['photo_file_id']) ? '<img loading="lazy" alt="" src="media.php?u=' . (int) $u['id'] . '">' : '';
        return '<span class="avatar ' . $size . '" style="--h:' . $hue . '">' . $initial . $img . '</span>';
    }

    /** Link to a user page with avatar + nickname. */
    public static function who(?array $u, bool $avatar = true): string
    {
        if (!$u || empty($u['id'])) {
            return '<span class="muted">—</span>';
        }
        return '<a class="who" href="?p=user&id=' . (int) $u['id'] . '">' . ($avatar ? self::avatar($u, 'sm') : '')
            . '<span>' . self::e($u['nickname'] ?? ('#' . $u['id'])) . '</span></a>';
    }

    public static function query(array $merge): string
    {
        return '?' . http_build_query(array_filter(array_merge($_GET, $merge), fn ($v) => $v !== null && $v !== ''));
    }

    /** Hidden CSRF token + where to come back to after the action. */
    public static function hidden(string $csrf): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="back" value="' . self::e($_SERVER['QUERY_STRING'] ?? '') . '">';
    }

    /** A one-button form that posts an action (with an optional "are you sure?"). */
    public static function action(string $csrf, string $action, array $fields, string $label, string $class = 'btn', ?string $confirm = null): string
    {
        $h = '<form method="post" class="inline"' . ($confirm ? ' data-confirm="' . self::e($confirm) . '"' : '') . '>' . self::hidden($csrf)
            . '<input type="hidden" name="action" value="' . self::e($action) . '">';
        foreach ($fields as $k => $v) {
            $h .= '<input type="hidden" name="' . self::e($k) . '" value="' . self::e($v) . '">';
        }
        return $h . '<button class="' . self::e($class) . '">' . self::e($label) . '</button></form>';
    }

    /** The real Bale account (admin only): "@username · Name", @username links to ble.ir. */
    public static function account(?array $a, bool $long = false): string
    {
        if (!$a || (empty($a['username']) && empty($a['first_name']) && empty($a['last_name']))) {
            return $long ? '<span class="muted">هنوز دریافت نشده (با پیام بعدی کاربر پر می‌شود)</span>' : '';
        }
        $name = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
        $user = !empty($a['username'])
            ? '<a class="acc-user" dir="ltr" href="https://ble.ir/' . rawurlencode($a['username']) . '" target="_blank" rel="noopener noreferrer">@' . self::e($a['username']) . '</a>'
            : '';
        return '<span class="acc">🪪 ' . implode(' · ', array_filter([$user, $name !== '' ? '<bdi>' . self::e($name) . '</bdi>' : ''])) . '</span>';
    }

    public static function reportStatus(string $s): string
    {
        return ['open' => self::badge('🚩 باز', 'bad'), 'resolved' => self::badge('✅ رسیدگی شد', 'ok'), 'dismissed' => self::badge('❌ رد شد')][$s] ?? self::badge($s);
    }

    public static function typeIcon(string $type): string
    {
        return ['text' => '💬', 'photo' => '🖼', 'voice' => '🎤', 'audio' => '🎵', 'video' => '🎬', 'animation' => '🎞', 'sticker' => '🙂', 'document' => '📎', 'video_note' => '⏺'][$type] ?? '⚠️';
    }
}
