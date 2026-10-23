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
            'birth'     => $n(2) . "تاریخ تولدت رو بنویس (شمسی).\nمثال: ۱۳۸۰/۰۵/۱۲\n🔒 تاریخ دقیق به کسی نشون داده نمی‌شه؛ فقط سنت.",
            'gender'    => $n(3) . 'جنسیتت؟',
            'pref'      => $n(4) . 'دوست داری با کی هم‌صحبت بشی؟',
            'photo'     => '📸 یک عکس برای پروفایلت بفرست' . ($reg ? ' (اختیاری)' : ''),
            'city'      => '📍 اهل کدوم شهری؟' . ($reg ? ' (اختیاری)' : ''),
            'bio'       => '📝 یه جمله درباره خودت بنویس (حداکثر ۲۰۰ حرف' . ($reg ? '، اختیاری' : '') . ')',
            'interests' => "🎯 علاقه‌مندی‌هات چیه؟ با ویرگول جدا کن (حداکثر ۵ تا" . ($reg ? '، اختیاری' : '') . ")\nمثال: موسیقی، فیلم، کتاب",
        };
        $rows = match ($step) {
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
        $s = $this->app->settings;

        if (in_array($step, ['gender', 'pref'], true)) {
            $this->say($user, '👆 از دکمه‌های بالا انتخاب کن.');
            return true;
        }
        if ($step === 'photo') {
            if (empty($m['photo'])) {
                $this->say($user, '📸 لطفاً یک عکس بفرست' . ($mode === 'reg' ? ' یا «⏭ رد شدن» رو بزن.' : '.'));
                return true;
            }
            $photo = $m['photo'][count($m['photo']) - 1];
            if ((int) ($photo['file_size'] ?? 0) > $s->int('max_file_mb') * 1048576) {
                $this->say($user, '⚠️ حجم عکس زیاده. یک عکس کوچک‌تر بفرست.');
                return true;
            }
            $users->update($id, ['photo_file_id' => (string) $photo['file_id']]);
        } else {
            if ($text === null) {
                $this->say($user, '✍️ لطفاً جوابت رو به‌صورت متن بنویس.');
                return true;
            }
            [$value, $error] = match ($step) {
                'name'      => Text::displayName($text),
                'nick'      => Text::nickname($text),
                'birth'     => Text::birthDate($text, $this->app->today(), max(13, $s->int('min_age')), $s->int('max_age')),
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
            . "\n\n💬 چت‌های رایگان امروز: " . Text::fa($q['free_left']) . ' از ' . Text::fa($q['limit']) . ' باقی‌مونده'
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
