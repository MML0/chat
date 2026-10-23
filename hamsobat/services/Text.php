<?php
// Input cleaning and validation (Persian-aware), Jalali dates, random public codes.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Text
{
    private const RESERVED_NICKNAMES = ['admin', 'administrator', 'support', 'hamsobat', 'hamsohbat', 'bale', 'moderator', 'ادمین', 'پشتیبانی', 'مدیر', 'همصحبت', 'هم‌صحبت'];

    /** Persian/Arabic digits → ASCII. */
    public static function digits(string $s): string
    {
        return strtr($s, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /** ASCII digits → Persian, for display. */
    public static function fa(int|string $s): string
    {
        return strtr((string) $s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    /** Arabic ي/ك → Persian ی/ک, no control characters, trimmed, single spaces. */
    public static function normalize(string $s, bool $keepNewlines = false): string
    {
        $s = strtr($s, ['ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی']);
        $s = preg_replace($keepNewlines ? '~[\x00-\x09\x0B-\x1F\x7F]~u' : '~[\x00-\x1F\x7F]~u', ' ', $s) ?? '';
        $s = preg_replace('~[ \t\x{00A0}]+~u', ' ', $s) ?? '';
        if ($keepNewlines) {
            $s = preg_replace('~\s*\n\s*(\n\s*)+~u', "\n\n", $s) ?? '';
        }
        return trim($s);
    }

    /** A link, @username or phone number. Used to keep chats anonymous and spam-free. */
    public static function hasContact(string $s): bool
    {
        $s = self::digits($s);
        if (preg_match('~(https?://|www\.|\b[a-z0-9-]{2,}\.(ir|com|net|org|me|io|app|ai|link|xyz|info|site|online|co)\b|ble\.ir|t\.me|@[A-Za-z0-9_]{4,})~iu', $s)) {
            return true;
        }
        // Phone numbers: 10+ digits, even with spaces or dashes in between (a date has only 8).
        preg_match_all('~\+?\d[\d\s.-]{6,}\d~', $s, $m);
        foreach ($m[0] as $run) {
            if (strlen(preg_replace('~\D~', '', $run)) >= 10) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:?string,1:?string} [value, Persian error] */
    public static function displayName(string $s): array
    {
        $s = self::normalize($s);
        if (!preg_match('~^[\p{L}\p{M}\x{200C} .\'-]{2,32}$~u', $s) || !preg_match('~\p{L}~u', $s)) {
            return [null, '❌ اسم باید ۲ تا ۳۲ حرف باشد (فقط حروف فارسی یا انگلیسی).'];
        }
        return [$s, null];
    }

    public static function nickname(string $s): array
    {
        $s = self::normalize($s);
        if (!preg_match('~^[\p{Arabic}A-Za-z0-9_\x{200C}]{3,20}$~u', $s) || !preg_match('~[\p{Arabic}A-Za-z]~u', $s)) {
            return [null, '❌ لقب باید ۳ تا ۲۰ کاراکتر باشد: حروف فارسی یا انگلیسی، عدد و _ (بدون فاصله).'];
        }
        if (in_array(mb_strtolower($s), self::RESERVED_NICKNAMES, true) || self::hasContact($s)) {
            return [null, '❌ این لقب مجاز نیست. یکی دیگه انتخاب کن.'];
        }
        return [$s, null];
    }

    public static function city(string $s): array
    {
        $s = self::normalize($s);
        if (!preg_match('~^[\p{L}\p{M}\x{200C} .-]{2,32}$~u', $s)) {
            return [null, '❌ اسم شهر باید ۲ تا ۳۲ حرف باشد.'];
        }
        return [$s, null];
    }

    public static function bio(string $s): array
    {
        $s = self::normalize($s, true);
        if (mb_strlen($s) < 2 || mb_strlen($s) > 200) {
            return [null, '❌ متن «درباره من» باید بین ۲ تا ۲۰۰ حرف باشد.'];
        }
        if (self::hasContact($s)) {
            return [null, '❌ لینک، آیدی یا شماره تلفن در «درباره من» مجاز نیست.'];
        }
        return [$s, null];
    }

    /** "موسیقی، فیلم, کتاب" → ['موسیقی', 'فیلم', 'کتاب'] (max 5). */
    public static function interests(string $s): array
    {
        $out = [];
        foreach (preg_split('~[,،;\n]+~u', self::normalize($s, true)) as $item) {
            $item = self::normalize($item);
            if ($item === '') {
                continue;
            }
            if (!preg_match('~^[\p{L}\p{M}\x{200C} -]{2,24}$~u', $item)) {
                return [null, '❌ هر علاقه‌مندی باید ۲ تا ۲۴ حرف باشد (فقط حروف). با ویرگول جدا کن.'];
            }
            $out[mb_strtolower($item)] = $item;
        }
        if (!$out || count($out) > 5) {
            return [null, '❌ بین ۱ تا ۵ علاقه‌مندی بنویس و با ویرگول جدا کن.'];
        }
        return [array_values($out), null];
    }

    /**
     * Birth date in Jalali (1380/05/12) or Gregorian (2001-08-03), Persian digits allowed.
     * @return array{0:?string,1:?string} [Y-m-d Gregorian, Persian error]
     */
    public static function birthDate(string $s, string $today, int $minAge, int $maxAge): array
    {
        $bad = [null, "❌ تاریخ رو این‌طوری بنویس: ۱۳۸۰/۰۵/۱۲"];
        if (!preg_match('~^\s*(\d{4})\s*[/.\-\s]\s*(\d{1,2})\s*[/.\-\s]\s*(\d{1,2})\s*$~', self::digits($s), $m)) {
            return $bad;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return $bad;
        }
        if ($y >= 1280 && $y <= 1500) {
            [$gy, $gm, $gd] = self::jalaliToGregorian($y, $mo, $d);
            if (self::gregorianToJalali($gy, $gm, $gd) !== [$y, $mo, $d]) {
                return $bad; // e.g. 1380/12/30 in a non-leap year
            }
        } elseif ($y >= 1900 && $y <= 2100 && checkdate($mo, $d, $y)) {
            [$gy, $gm, $gd] = [$y, $mo, $d];
        } else {
            return $bad;
        }
        $date = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
        $age = self::age($date, $today);
        if ($age < 0 || $date > $today) {
            return $bad;
        }
        if ($age < $minAge) {
            return [null, '❌ برای استفاده از هم‌صحبت باید حداقل ' . self::fa($minAge) . ' سال داشته باشی.'];
        }
        if ($age > $maxAge) {
            return $bad;
        }
        return [$date, null];
    }

    public static function age(string $birthDate, string $today): int
    {
        [$by, $bm, $bd] = array_map('intval', explode('-', $birthDate));
        [$ty, $tm, $td] = array_map('intval', explode('-', $today));
        return $ty - $by - (($tm < $bm || ($tm === $bm && $td < $bd)) ? 1 : 0);
    }

    public static function jalaliDate(string $gregorian): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $gregorian));
        [$jy, $jm, $jd] = self::gregorianToJalali($y, $m, $d);
        return self::fa(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }

    /** Standard Jalali ↔ Gregorian conversion (jdf algorithm). */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + $days % 31];
        }
        return [$jy, 7 + intdiv($days - 186, 30), 1 + ($days - 186) % 30];
    }

    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + 365 * $jy + intdiv($jy, 33) * 8 + intdiv($jy % 33 + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
        $months = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 0; $gm < 13 && $gd > $months[$gm]; $gm++) {
            $gd -= $months[$gm];
        }
        return [$gy, $gm, $gd];
    }

    /** Random lowercase letters + digits (safe in any deep link), e.g. for referral and inbox links. */
    public static function code(int $length): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789'; // no 0/o/1/l
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
