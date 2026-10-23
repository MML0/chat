<?php
// Profile: registration one question at a time, "my profile", editing.
// States: reg:<step> during registration, edit:<step> while editing one field.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class ProfileHandler extends Handler
{
    private const OPTIONAL = ['photo', 'city', 'bio', 'interests'];
    private const STEPS = ['name', 'nick', 'birth', 'gender', 'pref', 'photo', 'city', 'bio', 'interests'];
    private const COLUMN = ['photo' => 'photo_file_id', 'city' => 'city', 'bio' => 'bio'];

    /** 📝 تکمیل پروفایل */
    public function begin(array $user): void
    {
        if ($user['profile_completed'] && !Users::missing($user)) {
            $this->showMine($user);
            return;
        }
        $this->say($user, "📝 بریم پروفایلت رو بسازیم! فقط چند تا سؤال کوتاه 😊", Ui::removeKeyboard());
        $this->advance($user);
    }

    /** Asks the next registration question, or finishes. */
    private function advance(array $user, ?string $after = null): void
    {
        $user = $this->fresh($user);
        if ($missing = Users::missing($user)) {
            $this->ask($user, 'reg', $missing[0]);
            return;
        }
        if (!$user['profile_completed']) {
            $this->app->users()->update((int) $user['id'], ['profile_completed' => 1]);
            $user['profile_completed'] = 1;
            $this->app->handler(ReferralHandler::class)->qualify($user);
            $this->say($user, "✅ عالی! پروفایل اصلیت ساخته شد 🎉\nچند تا سؤال اختیاری هم مونده؛ اگه نخواستی «⏭ رد شدن» رو بزن.");
            $this->ask($user, 'reg', self::OPTIONAL[0]);
            return;
        }
        $i = $after === null ? -1 : array_search($after, self::OPTIONAL, true);
        if ($i !== false && $i < count(self::OPTIONAL) - 1) {
            $this->ask($user, 'reg', self::OPTIONAL[$i + 1]);
            return;
        }
        $this->finish($user);
    }

    private function finish(array $user): void
    {
        $this->app->users()->clearState((int) $user['id']);
        $user = $this->fresh($user);
        $this->say($user, "✅ پروفایلت آماده‌ست!\n\n" . $this->ownCard($user), Ui::mainKeyboard());
        $this->app->handler(ReferralHandler::class)->showRequirementsOrReady($user);
    }

    private function ask(array $user, string $mode, string $step): void
    {
        $this->app->users()->setState((int) $user['id'], "{$mode}:{$step}");
        $reg = $mode === 'reg';
        $n = fn (int $i) => $reg ? ['1️⃣ ', '2️⃣ ', '3️⃣ ', '4️⃣ ', '5️⃣ '][$i] : '';
        $cancel = $reg ? null : ['❌ لغو', 'pe:x'];
        $p = $reg ? 'rg' : 'pe';
        $text = match ($step) {
            'name'      => $n(0) . "اسمت چیه؟\n(توی پروفایلت نشون داده می‌شه؛ لازم نیست اسم واقعیت باشه)",
            'nick'      => $n(1) . "یک لقب یکتا برای خودت انتخاب کن.\nمثال: Moon یا ماه_شب\n(۳ تا ۲۰ کاراکتر: حروف، عدد و _)",
            'birth'     => $n(2) . "🎂 سال تولدت رو انتخاب کن 👇\n(یا تاریخ رو تایپ کن، مثلاً ۱۳۸۰/۰۵/۱۲)\n🔒 تاریخ دقیق به کسی نشون داده نمی‌شه؛ فقط سنت.",
            'gender'    => $n(3) . 'جنسیتت؟',
            'pref'      => $n(4) . 'دوست داری با کی هم‌صحبت بشی؟',
            'photo'     => '📸 یک عکس برای پروفایلت بفرست' . ($reg ? ' (اختیاری)' : ''),
            'city'      => '📍 اهل کدوم شهری؟' . ($reg ? ' (اختیاری)' : ''),
            'bio'       => '📝 یه جمله درباره خودت بنویس (حداکثر ۲۰۰ حرف' . ($reg ? '، اختیاری' : '') . ')',
            'interests' => "🎯 علاقه‌مندی‌هات چیه؟ با ویرگول جدا کن (حداکثر ۵ تا" . ($reg ? '، اختیاری' : '') . ")\nمثال: موسیقی، فیلم، کتاب",
        };
        $rows = match ($step) {
            'birth'  => $this->yearRows(null),
            'gender' => [[['👩 دختر', "{$p}:g:f"], ['👨 پسر', "{$p}:g:m"]]],
            'pref'   => [[['👩 دختر', "{$p}:p:f"], ['👨 پسر', "{$p}:p:m"]], [['🎲 فرقی نمی‌کنه', "{$p}:p:any"]]],
            default  => [],
        };
        if ($reg && in_array($step, self::OPTIONAL, true)) {
            $rows[] = [['⏭ رد شدن', "rg:skip:{$step}"]];
        }
        if (!$reg) {
            $row = [$cancel];
            if (in_array($step, self::OPTIONAL, true) && $this->hasValue($user, $step)) {
                $row[] = ['🗑 حذف', "pe:del:{$step}"];
            }
            $rows[] = $row;
        }
        $this->say($user, $text, $rows ? Ui::inline($rows) : null);
    }

    /** A message while a reg:/edit: step is pending. Always consumes it. */
    public function onInput(array $user, string $state, array $data, array $m): bool
    {
        [$mode, $step] = explode(':', $state, 2) + [1 => ''];
        if (!in_array($step, self::STEPS, true)) {
            $this->app->users()->clearState((int) $user['id']);
            return false;
        }
        $id = (int) $user['id'];
        $text = isset($m['text']) ? (string) $m['text'] : null;
        $users = $this->app->users();

        if (in_array($step, ['gender', 'pref'], true)) {
            $this->say($user, '👆 از دکمه‌های بالا انتخاب کن.');
            return true;
        }
        if ($step === 'photo') {
            if (empty($m['photo'])) {
                $this->say($user, '📸 لطفاً یک عکس بفرست' . ($mode === 'reg' ? ' یا «⏭ رد شدن» رو بزن.' : '.'));
                return true;
            }
            $users->update($id, ['photo_file_id' => (string) (MessageRelay::bestPhoto($m['photo'])['file_id'] ?? '')]);
        } else {
            if ($text === null) {
                $this->say($user, '✍️ لطفاً جوابت رو به‌صورت متن بنویس.');
                return true;
            }
            [$value, $error] = match ($step) {
                'name'      => Text::displayName($text),
                'nick'      => Text::nickname($text),
                'birth'     => Text::birthDate($text, $this->app->today(), ...$this->ageLimits()),
                'city'      => Text::city($text),
                'bio'       => Text::bio($text),
                'interests' => Text::interests($text),
            };
            if ($error === null && $step === 'nick' && !$users->nicknameFree($value, $id)) {
                $error = '❌ این لقب قبلاً انتخاب شده. یکی دیگه بنویس.';
            }
            if ($error !== null) {
                $this->say($user, $error);
                return true;
            }
            try {
                match ($step) {
                    'name'      => $users->update($id, ['display_name' => $value]),
                    'nick'      => $users->update($id, ['nickname' => $value]),
                    'birth'     => $users->update($id, ['birth_date' => $value]),
                    'city'      => $users->update($id, ['city' => $value]),
                    'bio'       => $users->update($id, ['bio' => $value]),
                    'interests' => $users->setInterests($id, $value),
                };
            } catch (\PDOException $e) {
                if (!Database::isDuplicate($e)) {
                    throw $e;
                }
                $this->say($user, '❌ این لقب همین الان انتخاب شد. یکی دیگه بنویس.');
                return true;
            }
        }
        $mode === 'reg' ? $this->advance($user, $step) : $this->edited($user);
        return true;
    }

    private function edited(array $user): void
    {
        $this->app->users()->clearState((int) $user['id']);
        $this->say($user, '✅ ذخیره شد.', $this->keyboardFor($user));
        $this->showMine($this->fresh($user));
    }

    public function onCallback(array $user, string $data, array $cb): string|array|null
    {
        $parts = explode(':', $data);
        $id = (int) $user['id'];
        $users = $this->app->users();
        [$state] = $users->state($user);

        if ($parts[0] === 'bd') {
            return $this->datePicker($user, $state, $parts, $cb);
        }

        switch ($parts[0] . ':' . ($parts[1] ?? '')) {
            case 'rg:g':
            case 'rg:p':
            case 'pe:g':
            case 'pe:p':
                $field = $parts[1] === 'g' ? 'gender' : 'preferred_gender';
                $allowed = $parts[1] === 'g' ? ['m', 'f'] : ['m', 'f', 'any'];
                $value = $parts[2] ?? '';
                if (!in_array($value, $allowed, true)) {
                    return null;
                }
                $step = $parts[1] === 'g' ? 'gender' : 'pref';
                if ($parts[0] === 'rg' && $state !== "reg:{$step}") {
                    return 'این سؤال قبلاً جواب داده شده.';
                }
                if ($parts[0] === 'pe' && $state !== "edit:{$step}") {
                    return 'برای ویرایش دوباره از «ویرایش پروفایل» شروع کن.';
                }
                $users->update($id, [$field => $value]);
                $parts[0] === 'rg' ? $this->advance($user, $step) : $this->edited($user);
                return '✅';

            case 'rg:skip':
                $step = $parts[2] ?? '';
                if (!in_array($step, self::OPTIONAL, true) || $state !== "reg:{$step}") {
                    return null;
                }
                $this->advance($user, $step);
                return null;

            case 'pe:del':
                $step = $parts[2] ?? '';
                if (!in_array($step, self::OPTIONAL, true) || $state !== "edit:{$step}") {
                    return null;
                }
                $step === 'interests' ? $users->setInterests($id, []) : $users->update($id, [self::COLUMN[$step] => null]);
                $this->edited($user);
                return '🗑 حذف شد';

            case 'pe:x':
                $users->clearState($id);
                $this->say($user, 'باشه، تغییری ندادم 👌', $this->keyboardFor($user));
                return null;

            case 'pf:show':
                $this->showMine($user);
                return null;

            case 'pf:edit':
                if (!$user['profile_completed']) {
                    $this->begin($user);
                    return null;
                }
                $this->say($user, '✏️ کدوم بخش رو می‌خوای ویرایش کنی؟', Ui::inline([
                    [['🙂 اسم', 'pe:name'], ['🔤 لقب', 'pe:nick'], ['🎂 تاریخ تولد', 'pe:birth']],
                    [['⚧ جنسیت', 'pe:gender'], ['🎯 جنسیت هم‌صحبت', 'pe:pref']],
                    [['📸 عکس', 'pe:photo'], ['📍 شهر', 'pe:city']],
                    [['📝 درباره من', 'pe:bio'], ['🎯 علاقه‌مندی‌ها', 'pe:interests']],
                ]));
                return null;
        }

        // pe:<step> → start editing one field
        if ($parts[0] === 'pe' && count($parts) === 2 && in_array($parts[1], self::STEPS, true)) {
            if (!$user['profile_completed']) {
                $this->begin($user);
                return null;
            }
            if ($this->app->matching()->activeChat($id)) {
                return ['text' => '💬 اول چت فعلی رو تموم کن، بعد پروفایلت رو ویرایش کن.', 'alert' => true];
            }
            $this->ask($user, 'edit', $parts[1]);
        }
        return null;
    }

    // ------------------------------------------------------------------ birth date picker
    // Bale has no native date picker for bots (reply buttons can only share a contact or a
    // location), so this is a Shamsi picker made of glass buttons: year → month → day, edited
    // in place. Every button has a visible label (Bale refuses empty ones). Typing still works.

    private const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    private const YEARS_PER_PAGE = 12;

    /** [min, max] age from the settings, exactly as the admin set them. */
    private function ageLimits(): array
    {
        $min = max(0, $this->app->settings->int('min_age'));
        return [$min, max($min, $this->app->settings->int('max_age'))];
    }

    /** [youngest, oldest] allowed Jalali birth years. */
    private function yearRange(): array
    {
        [$cy] = Text::gregorianToJalali(...array_map('intval', explode('-', $this->app->today())));
        [$min, $max] = $this->ageLimits();
        return [$cy - $min, $cy - $max];
    }

    /** Year buttons, newest first, 12 per page. $start = first (newest) year of the page. */
    private function yearRows(?int $start): array
    {
        [$youngest, $oldest] = $this->yearRange();
        $start = min($youngest, max($oldest, $start ?? $youngest));
        $rows = [];
        $row = [];
        for ($y = $start; $y > $start - self::YEARS_PER_PAGE && $y >= $oldest; $y--) {
            $row[] = [Text::fa($y), "bd:y:{$y}"];
            if (count($row) === 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        $nav = [];
        if ($start - self::YEARS_PER_PAGE >= $oldest) {
            $nav[] = ['« قدیمی‌تر', 'bd:yp:' . ($start - self::YEARS_PER_PAGE)];
        }
        if ($start < $youngest) {
            $nav[] = ['جدیدتر »', 'bd:yp:' . min($youngest, $start + self::YEARS_PER_PAGE)];
        }
        if ($nav) {
            $rows[] = $nav;
        }
        return $rows;
    }

    private function pickerMarkup(array $rows, string $mode): array
    {
        if ($mode === 'edit') {
            $rows[] = [['❌ لغو', 'pe:x']];
        }
        return Ui::inline($rows);
    }

    private function datePicker(array $user, string $state, array $parts, array $cb): string|array|null
    {
        if ($state !== 'reg:birth' && $state !== 'edit:birth') {
            return 'این سؤال قبلاً جواب داده شده.';
        }
        $mode = strstr($state, ':', true);
        [$youngest, $oldest] = $this->yearRange();
        $year = (int) ($parts[2] ?? 0);
        if (($parts[1] ?? '') !== 'yp' && ($year > $youngest || $year < $oldest)) {
            return null;
        }
        switch ($parts[1] ?? '') {
            case 'yp': // another page of years
                $this->editOrSend($user, $cb, "🎂 سال تولدت رو انتخاب کن 👇\n(یا تاریخ رو تایپ کن، مثلاً ۱۳۸۰/۰۵/۱۲)", $this->pickerMarkup($this->yearRows($year), $mode));
                return null;

            case 'y': // year chosen → months
                $rows = array_map(
                    fn ($chunk) => array_map(fn ($m) => [self::MONTHS[$m - 1], "bd:m:{$year}:{$m}"], $chunk),
                    array_chunk(range(1, 12), 3)
                );
                $page = $youngest - intdiv($youngest - $year, self::YEARS_PER_PAGE) * self::YEARS_PER_PAGE;
                $rows[] = [['🔙 تغییر سال', "bd:yp:{$page}"]];
                $this->editOrSend($user, $cb, '🎂 سال ' . Text::fa($year) . " ✅\nماه تولدت؟", $this->pickerMarkup($rows, $mode));
                return null;

            case 'm': // month chosen → days (only real days: no empty buttons)
                $month = (int) ($parts[3] ?? 0);
                if ($month < 1 || $month > 12) {
                    return null;
                }
                $days = $month <= 6 ? 31 : ($month <= 11 ? 30 : (Text::gregorianToJalali(...Text::jalaliToGregorian($year, 12, 30)) === [$year, 12, 30] ? 30 : 29));
                $rows = array_map(
                    fn ($chunk) => array_map(fn ($d) => [Text::fa($d), "bd:d:{$year}:{$month}:{$d}"], $chunk),
                    array_chunk(range(1, $days), 7)
                );
                $rows[] = [['🔙 تغییر ماه', "bd:y:{$year}"]];
                $this->editOrSend($user, $cb, '🎂 ' . self::MONTHS[$month - 1] . ' ' . Text::fa($year) . "\nروز تولدت؟", $this->pickerMarkup($rows, $mode));
                return null;

            case 'd': // day chosen → save
                [$date, $error] = Text::birthDate(($parts[2] ?? '') . '/' . ($parts[3] ?? '') . '/' . ($parts[4] ?? ''), $this->app->today(), ...$this->ageLimits());
                if ($error !== null) {
                    return ['text' => $error, 'alert' => true];
                }
                $this->app->users()->update((int) $user['id'], ['birth_date' => $date]);
                $this->editOrSend($user, $cb, '🎂 تاریخ تولد: ' . Text::jalaliDate($date) . ' (' . Text::fa(Text::age($date, $this->app->today())) . ' ساله) ✅');
                $mode === 'reg' ? $this->advance($user, 'birth') : $this->edited($user);
                return '✅';
        }
        return null;
    }

    /** 👤 پروفایل من */
    public function showMine(array $user): void
    {
        if (!$user['profile_completed'] || Users::missing($user)) {
            $this->say($user, '📝 هنوز پروفایلت کامل نشده.', Ui::onboardingKeyboard());
            return;
        }
        $q = $this->app->quota()->status((int) $user['id']);
        $refs = $this->app->referrals();
        $text = "👤 پروفایل من\n\n" . $this->ownCard($user)
            . ($q['unlimited']
                ? "\n\n💎 چت نامحدود" . ($q['unlimited_until'] ? ' تا ' . Text::jalaliDate(substr($this->app->today(strtotime($q['unlimited_until'] . ' UTC')), 0, 10)) : '') . ' ✨'
                : "\n\n💬 چت‌های رایگان امروز: " . Text::fa($q['free_left']) . ' از ' . Text::fa($q['limit']) . ' باقی‌مونده')
            . "\n🎁 اعتبار چت اضافه: " . Text::fa($q['credits'])
            . "\n👥 دعوت‌های موفق: " . Text::fa($refs->qualifiedCount((int) $user['id']));
        $markup = Ui::inline([[['✏️ ویرایش پروفایل', 'pf:edit'], ['🔒 حریم خصوصی', 'st:home']]]);
        if ($user['photo_file_id']) {
            $res = $this->app->bale->sendMedia('photo', (int) $user['bale_user_id'], $user['photo_file_id'], $text, $markup);
            if (!empty($res['ok'])) {
                return;
            }
        }
        $this->say($user, $text, $markup);
    }

    private function ownCard(array $user): string
    {
        return Ui::card($user, $this->app->today(), $this->app->users()->interests((int) $user['id']), true, true);
    }

    private function hasValue(array $user, string $step): bool
    {
        return $step === 'interests' ? (bool) $this->app->users()->interests((int) $user['id']) : !empty($user[self::COLUMN[$step]]);
    }
}
