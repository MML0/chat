<?php
// Hamsobat tests: php tests/run.php   (needs the MariaDB/MySQL test database, see README)
// Concurrency is tested separately: php tests/concurrency.php
require __DIR__ . '/lib.php';

use Hamsobat\Text;
use Hamsobat\Ui;
use Hamsobat\Database;
use Hamsobat\MessageRelay;

echo "Validation\n";

test('Jalali ↔ Gregorian and birth dates', function () {
    ok(Text::jalaliToGregorian(1380, 5, 12) === [2001, 8, 3], '1380/05/12 = 2001-08-03');
    ok(Text::gregorianToJalali(2026, 3, 21) === [1405, 1, 1], 'Nowruz 1405');
    [$d] = Text::birthDate('۱۳۸۰/۰۵/۱۲', '2026-03-10', 18, 80);
    ok($d === '2001-08-03', 'Persian digits parsed');
    ok(Text::birthDate('1399/12/30', '2026-03-10', 18, 80)[0] === '2021-03-20' || Text::birthDate('1399/12/30', '2026-03-10', 0, 80)[0] === '2021-03-20', '1399 is a leap year');
    ok(Text::birthDate('1400/12/30', '2026-03-10', 0, 80)[0] === null, '1400/12/30 does not exist');
    ok(Text::birthDate('2001-08-03', '2026-03-10', 18, 80)[0] === '2001-08-03', 'Gregorian accepted');
    ok(Text::birthDate('1390/01/01', '2026-03-10', 18, 80)[0] === null, 'under 18 refused');
    ok(contains((string) Text::birthDate('1390/01/01', '2026-03-10', 18, 80)[1], '۱۸'), 'says the minimum age');
    ok(Text::birthDate('hello', '2026-03-10', 18, 80)[0] === null, 'garbage refused');
    ok(Text::birthDate('1410/01/01', '2026-03-10', 0, 80)[0] === null, 'future refused');
    ok(Text::age('2001-08-03', '2026-03-10') === 24, 'age');
});

test('Names, nicknames, contact filter', function () {
    ok(Text::displayName('سارا')[0] === 'سارا', 'Persian name');
    ok(Text::displayName('a')[0] === null && Text::displayName('09121234567')[0] === null, 'bad names');
    ok(Text::nickname('Moon')[0] === 'Moon' && Text::nickname('ماه_شب')[0] === 'ماه_شب', 'nicknames');
    ok(Text::nickname('ab')[0] === null && Text::nickname('has space')[0] === null && Text::nickname('admin')[0] === null && Text::nickname('1234')[0] === null, 'bad nicknames');
    ok(Text::nickname('علي')[0] === 'علی', 'Arabic yeh normalised');
    foreach (['https://x.ir', 'www.site.com', 'my @username here', 'call 0912 123 4567', '+989121234567', 'ble.ir/abc', 'site.ir'] as $s) {
        ok(Text::hasContact($s), "contact: {$s}");
    }
    foreach (['سلام چطوری؟', 'I was born 1380/05/12', 'ساعت ۱۲:۳۰', 'my score is 12345'] as $s) {
        ok(!Text::hasContact($s), "no contact: {$s}");
    }
    ok(Text::interests('موسیقی، فیلم, کتاب')[0] === ['موسیقی', 'فیلم', 'کتاب'], 'interests split');
    ok(Text::interests('a,b,c,d,e,f')[0] === null, 'max 5 interests');
});

test('Button labels match with or without emoji variation selectors', function () {
    ok(Ui::label('⚙️ تنظیمات') === Ui::SETTINGS && Ui::label("⚙ تنظیمات") === Ui::SETTINGS, 'FE0F ignored');
    ok(Ui::label('🔎 پیدا کردن همصحبت') === Ui::FIND, 'missing half-space ignored');
    ok(Ui::label('سلام') === null, 'normal text is not a label');
});

test('Schema: migrate twice is harmless, prefix replaced everywhere', function () {
    $w = new World();
    foreach (Database::schema(dirname(__DIR__) . '/database.sql', 't_') as $sql) {
        $w->app->db->pdo->exec($sql);
    }
    $sql = implode("\n", Database::schema(dirname(__DIR__) . '/database.sql', 'hs2_'));
    ok(!preg_match('~\bhs_~', $sql) && str_contains($sql, 'hs2_fk_users_referred'), 'no hs_ left, constraint names prefixed');
    ok(!preg_match('~\bDROP\b|\bTRUNCATE\b|DELETE\s+FROM|ALTER\s+TABLE~i', $sql), 'never drops or changes anything');
});

echo "\nStart and registration\n";

test('/start creates the user once and shows the welcome', function () {
    $w = new World();
    $w->msg(1, '/start');
    $w->msg(1, '/start');
    ok($w->count('SELECT COUNT(*) FROM {users} WHERE bale_user_id = 1') === 1, 'one row');
    ok(contains($w->bale->lastText(1), 'به هم‌صحبت خوش اومدی'), 'welcome');
    $kb = end($w->bale->calls)['p']['reply_markup']['keyboard'] ?? [];
    ok(($kb[0][0]['text'] ?? '') === Ui::COMPLETE_PROFILE && ($kb[1][0]['text'] ?? '') === Ui::GUIDE, 'onboarding reply keyboard');
});

test('Registration one question at a time, with validation', function () {
    $w = new World();
    $w->msg(1, '/start');
    $w->msg(1, Ui::COMPLETE_PROFILE);
    ok(($w->bale->calls[count($w->bale->calls) - 2]['p']['reply_markup']['remove_keyboard'] ?? false) === true, 'keyboard removed for typing');
    ok(contains($w->bale->lastText(1), 'اسمت چیه'), 'asks name');
    $w->msg(1, '!!');
    ok(contains($w->bale->lastText(1), 'اسم باید'), 'bad name refused');
    $w->msg(1, 'سارا');
    ok(contains($w->bale->lastText(1), 'لقب'), 'asks nickname');
    $w->ready(2, 'Moon');
    $w->msg(1, 'moon');
    ok(contains($w->bale->lastText(1), 'قبلاً انتخاب شده'), 'nickname taken (case-insensitive)');
    $w->msg(1, 'Star');
    $w->msg(1, '1395/01/01');
    ok(contains($w->bale->lastText(1), '۱۸'), 'under 18 refused');
    $w->msg(1, '1380/05/12');
    ok(contains($w->bale->lastText(1), 'جنسیتت'), 'asks gender');
    $w->msg(1, 'دختر');
    ok(contains($w->bale->lastText(1), 'دکمه'), 'gender needs a button');
    $w->cb(1, 'rg:g:f');
    $w->cb(1, 'rg:g:m'); // stale button: ignored
    ok($w->user(1)['gender'] === 'f', 'gender saved once');
    $w->cb(1, 'rg:p:x');
    ok($w->user(1)['preferred_gender'] === null, 'invalid callback value ignored');
    $w->cb(1, 'rg:p:m');
    $u = $w->user(1);
    ok($u['profile_completed'] == 1 && $u['birth_date'] === '2001-08-03', 'completed after required fields');
    ok(contains($w->bale->lastText(1), 'عکس'), 'optional photo step');
    $w->media(1, 'photo', [['file_id' => 'small', 'file_size' => 10], ['file_id' => 'BIG', 'file_size' => 2000]]);
    $w->msg(1, 'تهران');
    $w->msg(1, 'عاشق موسیقی و فیلم');
    $w->msg(1, 'موسیقی، فیلم');
    $u = $w->user(1);
    ok($u['photo_file_id'] === 'BIG' && $u['city'] === 'تهران' && $u['bio'] === 'عاشق موسیقی و فیلم', 'optional fields saved');
    ok($w->app->users()->interests((int) $u['id']) === ['موسیقی', 'فیلم'], 'interests saved');
    ok($u['state'] === '', 'state cleared');
    $texts = implode("\n", $w->bale->texts(1));
    ok(contains($texts, 'پروفایلت آماده‌ست') && contains($texts, '🎂 سن: ۲۴'), 'summary with age');
    ok(contains($texts, 'برای شروع چت') && contains($texts, 'یک دوست'), 'requirements screen (needs a referral)');
});

test('A menu button leaves a pending step', function () {
    $w = new World();
    $w->msg(1, '/start');
    $w->msg(1, Ui::COMPLETE_PROFILE);
    $w->msg(1, Ui::GUIDE);
    ok($w->user(1)['state'] === '' && $w->user(1)['display_name'] === null, 'label not saved as a name');
    ok(contains($w->bale->lastText(1), 'راهنمای هم‌صحبت'), 'guide shown');
});

test('Profile edit and privacy toggles', function () {
    $w = new World();
    $w->ready(1, 'Moon');
    $w->cb(1, 'pe:city');
    $w->msg(1, 'شیراز');
    ok($w->user(1)['city'] === 'شیراز', 'city edited');
    $w->cb(1, 'pe:bio');
    $w->msg(1, 'تماس: 09121234567');
    ok($w->user(1)['bio'] === null && contains($w->bale->lastText(1), 'مجاز نیست'), 'phone in bio refused');
    $w->cb(1, 'pe:x');
    $w->cb(1, 'st:t:city');
    ok($w->user(1)['show_city'] == 0, 'city hidden');
    $w->cb(1, 'pe:gender');
    $w->cb(1, 'pe:g:f');
    ok($w->user(1)['gender'] === 'f', 'gender edited');
});

echo "\nReferrals and access\n";

test('Referral: new user qualifies once, inviter rewarded once and unlocked', function () {
    $w = new World(['referral_reward_credits' => 3]);
    $a = $w->register(1, 'Inviter');
    $w->find(1);
    ok(contains($w->bale->lastText(1), 'یک دوست') && !$w->app->matching()->isSearching((int) $a['id']), 'locked before a referral');
    $w->register(2, 'Friend', 'f', 'any', '1376/02/02', 'ref' . $a['referral_code']);
    $b = $w->user(2);
    ok((int) $b['referred_by_user_id'] === (int) $a['id'], 'inviter recorded');
    ok($w->count("SELECT COUNT(*) FROM {referrals} WHERE status = 'qualified'") === 1, 'qualified after profile');
    ok($w->app->quota()->status((int) $a['id'])['credits'] === 3, '3 credits');
    ok(contains(implode("\n", $w->bale->texts(1)), 'چت ناشناس برات باز شد'), 'inviter told it is unlocked');
    // Replays and tricks
    $w->app->referrals()->tryQualify($w->user(2));
    $w->msg(2, '/start ref' . $a['referral_code']);
    ok($w->app->quota()->status((int) $a['id'])['credits'] === 3, 'no second reward');
    $c = $w->register(3, 'Other');
    $w->msg(2, '/start ref' . $c['referral_code']);
    ok((int) $w->user(2)['referred_by_user_id'] === (int) $a['id'], 'inviter cannot be replaced');
    $w->find(1);
    ok($w->app->matching()->isSearching((int) $a['id']), 'unlocked: searching');
});

test('Referral abuse: self, old accounts, bad codes, daily cap', function () {
    $w = new World(['referral_daily_reward_cap' => 1]);
    $a = $w->register(1, 'Inviter');
    $w->msg(1, '/start ref' . $a['referral_code']);
    ok($w->count('SELECT COUNT(*) FROM {referrals}') === 0, 'no self-referral');
    $old = $w->register(2, 'OldUser');
    $w->msg(2, '/start ref' . $a['referral_code']);
    ok($w->count('SELECT COUNT(*) FROM {referrals}') === 0, 'completed account cannot be referred');
    $w->msg(3, '/start');
    $w->time += 2 * 3600; // created 2 h ago, outside the 60 min window
    $w->msg(3, '/start ref' . $a['referral_code']);
    ok($w->count('SELECT COUNT(*) FROM {referrals}') === 0, 'old account is not new');
    $w->msg(4, '/start refAAAAAAAA');
    $w->msg(4, "/start ref' OR 1=1");
    ok($w->count('SELECT COUNT(*) FROM {referrals}') === 0 && contains($w->bale->lastText(4), 'خوش اومدی'), 'bad codes ignored');
    $w->register(5, 'Friend1', 'm', 'any', '1375/01/01', 'ref' . $a['referral_code']);
    $w->register(6, 'Friend2', 'm', 'any', '1375/01/01', 'ref' . $a['referral_code']);
    ok($w->count("SELECT COUNT(*) FROM {referrals} WHERE status = 'qualified'") === 2, 'both qualified');
    ok($w->app->quota()->status((int) $a['id'])['credits'] === 3, 'reward capped at 1 per day');
});

test('Channel membership is checked on the server', function () {
    $w = new World(['require_channel' => 1, 'required_channel' => '@hamsohbat', 'channel_link' => 'https://ble.ir/hamsohbat', 'required_referrals' => 0]);
    $w->register(1, 'Moon');
    $w->msg(1, Ui::FIND);
    $last = end($w->bale->calls);
    ok(contains($w->bale->lastText(1), 'عضو کانال شو'), 'requirements shown (before asking anything)');
    ok(($last['p']['reply_markup']['inline_keyboard'][0][0]['url'] ?? '') === 'https://ble.ir/hamsohbat', 'join button is a link');
    $w->cb(1, 'rq');
    ok(contains($w->bale->lastText(1), 'عضو کانال شو'), 'pressing "check" without joining does not pass');
    $w->bale->members[1] = 'member';
    $w->cb(1, 'rq');
    ok(contains($w->bale->lastText(1), 'همه‌چیز آماده‌ست'), 'member passes');
    $w->bale->channelBroken = true;
    $w->app->db->run('UPDATE {users} SET channel_verified_at = NULL');
    $w->find(1);
    ok(contains($w->bale->lastText(1), 'عضو کانال'), 'API error fails closed');
    ok(contains(implode("\n", $w->bale->texts(9000)), 'پیدا نمی‌کند'), 'admin alerted with the fix');
});

test('Referral counts only after the friend joins the channel (if required)', function () {
    $w = new World(['require_channel' => 1, 'required_channel' => '@hamsohbat', 'referral_requires_channel' => 1]);
    $a = $w->register(1, 'Inviter');
    $w->register(2, 'Friend', 'f', 'any', '1376/01/01', 'ref' . $a['referral_code']);
    ok($w->count("SELECT COUNT(*) FROM {referrals} WHERE status = 'pending'") === 1, 'pending until the friend joins');
    $w->bale->members[2] = 'member';
    $w->cb(2, 'rq');
    ok($w->count("SELECT COUNT(*) FROM {referrals} WHERE status = 'qualified'") === 1, 'qualified after joining');
});

echo "\nMatching and quota\n";

test('Two users are matched; credits only spent on a match', function () {
    $w = new World(['daily_free_chats' => 5]);
    $a = $w->ready(1, 'Alpha');
    $b = $w->ready(2, 'Beta', 'f');
    $w->find(1);
    ok(contains($w->bale->lastText(1), 'داریم برات هم‌صحبت پیدا می‌کنیم'), 'searching');
    $kb = last(array_values(array_filter($w->bale->to(1), fn ($c) => isset($c['p']['reply_markup']['keyboard']))))['p']['reply_markup']['keyboard'][0][0]['text'] ?? '';
    ok($kb === Ui::CANCEL_SEARCH, 'cancel-search keyboard');
    ok($w->app->quota()->status((int) $a['id'])['free_left'] === 5, 'searching costs nothing');
    $w->find(2);
    ok($w->activeChat(1)['partner_id'] === (int) $b['id'] && $w->activeChat(2)['partner_id'] === (int) $a['id'], 'matched');
    ok($w->app->quota()->status((int) $a['id'])['free_left'] === 4 && $w->app->quota()->status((int) $b['id'])['free_left'] === 4, 'one chat each');
    $texts = implode("\n", $w->bale->texts(1));
    ok(contains($texts, 'هم‌صحبت پیدا شد') && contains($texts, 'لقب: Beta') && !contains($texts, '2001') && !contains($texts, (string) $b['bale_user_id'] . ')'), 'partner card, no ids or birth date');
    $chatKb = array_values(array_filter($w->bale->to(1), fn ($c) => isset($c['p']['reply_markup']['keyboard'])));
    ok((end($chatKb)['p']['reply_markup']['keyboard'][0][0]['text'] ?? '') === Ui::NEXT, 'chat reply keyboard');
});

test('Cancelled and timed-out searches cost nothing', function () {
    $w = new World(['search_timeout_seconds' => 30]);
    $a = $w->ready(1, 'Alpha');
    $w->find(1);
    $w->msg(1, Ui::CANCEL_SEARCH);
    ok(!$w->app->matching()->isSearching((int) $a['id']) && contains($w->bale->lastText(1), 'لغو شد'), 'cancelled');
    $w->find(1);
    $w->time += 31;
    ok(!$w->app->matching()->isSearching((int) $a['id']), 'expired after 30 s');
    $w->app->maintenance()->tick();
    ok(contains($w->bale->lastText(1), 'پیدا نشد'), 'told nobody was found');
    $b = $w->ready(2, 'Beta');
    $w->find(2);
    ok($w->activeChat(2) === null, 'expired user is not matched');
    ok($w->app->quota()->status((int) $a['id'])['free_left'] === 5, 'nothing spent');
});

test('Quota boundary: free chats, then bonus credits, then refused', function () {
    $w = new World(['daily_free_chats' => 2, 'rematch_cooldown_minutes' => 0]);
    $a = $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->app->quota()->grant((int) $a['id'], 1, 'admin', 'test');
    for ($i = 1; $i <= 3; $i++) {
        $w->find(1);
        $w->find(2);
        ok($w->activeChat(1) !== null, "chat {$i} started");
        $w->msg(1, Ui::END);
        $w->app->db->run('UPDATE {daily_usage} SET successful_chats = 0, bonus_credits_used = 0 WHERE user_id <> ?', [$a['id']]);
    }
    $s = $w->app->quota()->status((int) $a['id']);
    ok($s['free_left'] === 0 && $s['credits'] === 0 && $s['used'] === 3, '2 free + 1 credit used');
    ok($w->count("SELECT COUNT(*) FROM {credit_ledger} WHERE user_id = ? AND reason = 'chat'", [$a['id']]) === 1, 'credit spend in the ledger');
    $w->find(1);
    ok(contains($w->bale->lastText(1), 'سهمیه رایگان امروزت تموم شده') && !$w->app->matching()->isSearching((int) $a['id']), 'refused, not queued');
    $w->time += 86400; // next day in Tehran
    ok($w->app->quota()->status((int) $a['id'])['free_left'] === 2, 'new day, new free chats');
});

test('Preferences, blocks, minors and self are respected', function () {
    $w = new World(['min_age' => 13, 'rematch_cooldown_minutes' => 0]);
    $boyWantsGirl = $w->ready(1, 'BoyA', 'm', 'f');
    $w->ready(2, 'BoyB', 'm', 'any');
    $w->find(1);
    $w->find(2);
    ok($w->activeChat(1) === null, 'm wanting f is not matched with m');
    $w->ready(3, 'GirlWantsGirl', 'f', 'f');
    $w->find(3);
    ok($w->activeChat(3) === null, 'f wanting f is not matched with m wanting f');
    $w->ready(4, 'GirlAny', 'f', 'any');
    $w->find(4);
    ok(($w->activeChat(4)['partner_id'] ?? 0) === (int) $boyWantsGirl['id'], 'longest waiting compatible user first');
    // minors
    $teen = $w->ready(5, 'Teen', 'm', 'any', '1390/01/01');
    $w->find(5);
    ok($w->activeChat(5) === null && $w->activeChat(2) === null, 'a minor is never matched with an adult');
    $w->ready(6, 'Teen2', 'f', 'any', '1391/01/01');
    $w->find(6);
    ok(($w->activeChat(6)['partner_id'] ?? 0) === (int) $teen['id'], 'minors are matched with minors');
    // self: searching twice never matches yourself
    $w->find(2);
    ok($w->activeChat(2) === null && $w->count('SELECT COUNT(*) FROM {chat_queue} WHERE user_id = ?', [$w->user(2)['id']]) === 1, 'one queue row, no self-match');
});

test('Block ends the chat, stays silent and prevents matching both ways', function () {
    $w = new World(['rematch_cooldown_minutes' => 0]);
    $a = $w->ready(1, 'Alpha');
    $b = $w->ready(2, 'Beta');
    $w->find(1);
    $w->find(2);
    $chatId = $w->activeChat(1)['chat_id'];
    $w->msg(1, Ui::BLOCK);
    ok($w->activeChat(1) !== null, 'asks for confirmation first');
    $w->cb(1, "ch:bly:{$chatId}");
    ok($w->activeChat(1) === null && $w->activeChat(2) === null, 'chat ended');
    $partnerTexts = implode("\n", $w->bale->texts(2));
    ok(contains($partnerTexts, 'هم‌صحبتت چت رو تموم کرد') && !contains($partnerTexts, 'مسدود شد'), 'blocked user is not told');
    $w->find(2);
    $w->find(1);
    ok($w->activeChat(1) === null, 'never matched again (blocker searches)');
    $w->msg(1, Ui::CANCEL_SEARCH);
    $w->msg(2, Ui::CANCEL_SEARCH);
    $w->find(1);
    $w->find(2);
    ok($w->activeChat(2) === null, 'never matched again (blocked one searches)');
    $w->msg(1, Ui::CANCEL_SEARCH);
    $w->msg(2, Ui::CANCEL_SEARCH);
    $w->cb(1, "st:ub:{$b['id']}");
    $w->find(1);
    $w->find(2);
    ok($w->activeChat(1) !== null, 'matched again after unblock');
});

test('Rematch cooldown and "next"', function () {
    $w = new World(['rematch_cooldown_minutes' => 10]);
    $a = $w->ready(1, 'Alpha');
    $b = $w->ready(2, 'Beta');
    $c = $w->ready(3, 'Gamma');
    $w->find(1);
    $w->find(2);
    $first = $w->activeChat(1)['chat_id'];
    $w->msg(1, Ui::NEXT);
    ok(contains($w->bale->lastText(1), 'با کی می‌خوای'), '"next" asks who first');
    $w->cb(1, 'fg:any');
    ok($w->activeChat(2) === null && contains(implode("\n", $w->bale->texts(2)), 'هم‌صحبتت چت رو تموم کرد'), 'partner told');
    ok($w->app->matching()->isSearching((int) $a['id']), '"next" searches again');
    $w->find(2);
    ok($w->activeChat(2) === null, 'not rematched with the same person right away');
    $w->find(3);
    ok(($w->activeChat(3)['partner_id'] ?? 0) === (int) $a['id'], 'matched with someone new');
    $w->cb(1, "ch:end:{$first}");
    ok($w->activeChat(1) !== null, 'an old "end" button does not end the new chat');
});

echo "\nRelay\n";

test('Text and media are relayed anonymously; unsafe content filtered', function () {
    $w = new World(['msg_rate_per_minute' => 8]);
    $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->find(1);
    $w->find(2);
    $before = count($w->bale->calls);
    $w->msg(1, 'سلام! خوبی؟');
    $sent = array_slice($w->bale->calls, $before);
    ok(count($sent) === 1 && $sent[0]['method'] === 'sendMessage' && (int) $sent[0]['p']['chat_id'] === 2 && $sent[0]['p']['text'] === 'سلام! خوبی؟', 'text re-sent by the bot');
    ok(!$w->bale->of('forwardMessage'), 'never forwarded');
    $w->media(1, 'photo', [['file_id' => 'PH1', 'file_size' => 1000]], 'عکس من');
    $last = end($w->bale->calls);
    ok($last['method'] === 'sendPhoto' && $last['p']['photo'] === 'PH1' && $last['p']['caption'] === 'عکس من' && (int) $last['p']['chat_id'] === 2, 'photo by file_id');
    $w->media(1, 'voice', ['file_id' => 'V1', 'duration' => 5, 'file_size' => 5000]);
    ok(end($w->bale->calls)['method'] === 'sendVoice', 'voice');
    // Media is never downloaded, so length and size never block it (a 3 s voice may arrive as 3000 ms).
    $w->media(1, 'voice', ['file_id' => 'V3S', 'duration' => 3000, 'file_size' => 9000]);
    $last = end($w->bale->calls);
    ok($last['method'] === 'sendVoice' && $last['p']['voice'] === 'V3S' && (int) $last['p']['chat_id'] === 2, 'voice with a big duration number is relayed');
    $w->media(1, 'video', ['file_id' => 'HUGE', 'duration' => 900, 'file_size' => 400 * 1048576]);
    ok(end($w->bale->calls)['method'] === 'sendVideo' && end($w->bale->calls)['p']['video'] === 'HUGE', 'big video relayed by file_id');
    $w->media(1, 'photo', [['file_id' => 'LARGE', 'width' => 1280, 'height' => 960], ['file_id' => 'THUMB', 'width' => 90, 'height' => 67]]);
    ok(end($w->bale->calls)['p']['photo'] === 'LARGE', 'largest photo size chosen whatever the order');
    ok(!$w->bale->of('getFile'), 'nothing downloaded');
    $before = count($w->bale->to(2));
    $w->msg(1, 'آیدیم @my_handle هست');
    $w->media(1, 'contact', ['phone_number' => '09121234567']);
    ok(count($w->bale->to(2)) === $before, 'link and contact not delivered');
    ok(contains($w->bale->lastText(1), 'مجاز نیست'), 'sender told why');
    ok($w->count("SELECT COUNT(*) FROM {messages} WHERE delivery_status = 'filtered'") === 2, 'filtered messages logged');
    $w->msg(1, 'یک');
    $w->msg(1, 'دو');
    ok(contains($w->bale->lastText(1), 'آهسته‌تر'), 'rate limit');
    ok($w->count("SELECT message_count FROM {chats}") === 6, 'only delivered messages counted');
});

test('copyMessage is the fallback when re-sending fails', function () {
    $w = new World();
    $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->find(1);
    $w->find(2);
    $w->bale->failMethods['sendVideo'] = 'Bad Request: wrong file identifier';
    $w->media(1, 'video', ['file_id' => 'VID', 'file_size' => 100]);
    $last = end($w->bale->calls);
    ok($last['method'] === 'copyMessage' && (int) $last['p']['chat_id'] === 2 && (int) $last['p']['from_chat_id'] === 1, 'copyMessage fallback');
});

test('Partner who blocked the bot: chat ends, credits refunded on a dead match', function () {
    $w = new World();
    $a = $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->find(1);
    $w->find(2);
    $w->bale->unreachable[] = 2;
    $w->msg(1, 'سلام');
    ok($w->activeChat(1) === null && contains($w->bale->lastText(1), 'از ربات خارج شده'), 'chat ended on 403');
    // A waiting user who left the bot is skipped and both get their chat back.
    $c = $w->ready(3, 'Gamma');
    $w->find(3);
    $w->bale->unreachable = [3];
    $w->find(1);
    ok($w->activeChat(1) === null && $w->app->matching()->isSearching((int) $a['id']), 'dead match undone, searching again');
    ok($w->app->quota()->status((int) $c['id'])['free_left'] === 5 && $w->app->quota()->status((int) $a['id'])['free_left'] === 4, 'refunded (Alpha only paid for the real first chat)');
});

echo "\nProfile views, reports, moderation\n";

test('Viewing a profile respects privacy and notifies once per cooldown', function () {
    $w = new World(['profile_view_notify_minutes' => 60]);
    $w->ready(1, 'Alpha');
    $b = $w->ready(2, 'Beta');
    $w->app->db->run("UPDATE {users} SET city = 'تهران', bio = 'سلام', show_city = 0 WHERE id = ?", [$b['id']]);
    $w->find(1);
    $w->find(2);
    $chatId = $w->activeChat(1)['chat_id'];
    $w->cb(1, "ch:pv:{$chatId}");
    $card = $w->bale->lastText(1);
    ok(contains($card, 'لقب: Beta') && contains($card, 'درباره: سلام') && !contains($card, 'تهران'), 'hidden city not shown');
    $w->msg(1, Ui::VIEW_PROFILE);
    $notes = array_filter($w->bale->texts(2), fn ($t) => contains($t, 'پروفایلت رو دید'));
    ok(count($notes) === 1, 'one notification for two views');
    ok($w->count('SELECT COUNT(*) FROM {profile_views}') === 2, 'both views recorded');
    $w->cb(3, "ch:pv:{$chatId}");
    ok($w->count('SELECT COUNT(*) FROM {profile_views}') === 2, 'outsiders cannot view through a chat id');
});

test('Reports: reasons, duplicates, details, auto-suspension', function () {
    $w = new World(['auto_suspend_reports' => 2, 'rematch_cooldown_minutes' => 0]);
    $bad = $w->ready(1, 'Bad');
    $w->ready(2, 'Rep1');
    $w->ready(3, 'Rep2');
    $w->find(1);
    $w->find(2);
    $chat1 = $w->activeChat(2)['chat_id'];
    $w->msg(2, Ui::REPORT);
    ok(contains($w->bale->lastText(2), 'چرا می‌خوای'), 'asks reason');
    $w->cb(2, "rr:c:{$chat1}:oth");
    $w->msg(2, 'فحش داد');
    $w->cb(2, "rr:c:{$chat1}:msg");
    $r = $w->app->db->row('SELECT * FROM {reports}');
    ok($w->count('SELECT COUNT(*) FROM {reports}') === 1 && $r['details'] === 'فحش داد' && $r['reason'] === 'oth', 'one report with details');
    ok($w->activeChat(2) !== null, 'reporting alone does not relay the details to the partner');
    ok(!in_array('فحش داد', $w->bale->texts(1), true), 'details never reach the reported user');
    ok(contains(implode("\n", $w->bale->texts(9000)), 'گزارش جدید'), 'admin notified');
    $w->msg(2, Ui::END);
    $w->cb(3, "rr:c:{$chat1}:har");
    ok($w->count('SELECT COUNT(*) FROM {reports}') === 1, 'outsider cannot report a chat');
    $w->find(1);
    $w->find(3);
    $chat2 = $w->activeChat(3)['chat_id'];
    $w->cb(3, "rr:c:{$chat2}:har");
    ok($w->user(1)['status'] === 'suspended', 'suspended after 2 different reporters');
    ok($w->activeChat(3) === null, 'their chat ended');
    $w->find(1);
    ok(contains($w->bale->lastText(1), 'مسدود است') && $w->count("SELECT COUNT(*) FROM {chat_queue} WHERE status = 'waiting'") === 0, 'suspended user cannot search');
    $w->time += 25 * 3600;
    $w->find(1);
    ok($w->user(1)['status'] === 'active', 'suspension ends after 24 h');
});

echo "\nAnonymous inbox\n";

test('Anonymous link: validation, delivery, reply, no identity, no referral', function () {
    $w = new World();
    $owner = $w->ready(1, 'Owner');
    $w->msg(1, Ui::INBOX);
    ok(contains($w->bale->lastText(1), '?start=msg' . $owner['inbox_code']), 'link shown');
    $w->msg(5, '/start msgzzzzzzzzzz');
    ok(contains($w->bale->lastText(5), 'معتبر نیست'), 'unknown code refused');
    $w->msg(5, '/start msg<script>');
    ok(contains($w->bale->lastText(5), 'معتبر نیست'), 'malformed code refused');
    $w->msg(1, '/start msg' . $owner['inbox_code']);
    ok(contains($w->bale->lastText(1), 'لینک خودته'), 'own link');
    $w->msg(5, '/start msg' . $owner['inbox_code']);
    ok(contains($w->bale->lastText(5), 'داری برای «Owner» پیام ناشناس'), 'compose prompt');
    ok($w->count('SELECT COUNT(*) FROM {referrals}') === 0, 'opening the link is not a referral');
    $w->msg(5, 'سلام، ازت خوشم میاد');
    $got = $w->bale->lastText(1);
    ok(contains($got, 'یک پیام ناشناس جدید') && contains($got, 'ازت خوشم میاد') && !contains($got, 'U5') && !contains($got, '5'), 'delivered without identity');
    $btns = last($w->bale->to(1))['p']['reply_markup']['inline_keyboard'][0] ?? [];
    $msgId = (int) $w->app->db->value('SELECT id FROM {anonymous_inbox}');
    ok(($btns[0]['callback_data'] ?? '') === "ix:r:{$msgId}", 'reply button');
    ok(contains($w->bale->lastText(5), 'ناشناس ارسال شد'), 'sender confirmed');
    $w->msg(5, 'پیام دوم بدون لینک');
    ok($w->count('SELECT COUNT(*) FROM {anonymous_inbox}') === 1, 'one message per open link');
    $w->cb(1, "ix:r:{$msgId}");
    $w->msg(1, 'مرسی!');
    ok(contains($w->bale->lastText(5), 'به پیام ناشناست جواب داد') && contains($w->bale->lastText(5), 'مرسی!'), 'reply reaches the sender');
    $reply = (int) $w->app->db->value('SELECT MAX(id) FROM {anonymous_inbox}');
    $w->cb(5, "ix:r:{$reply}");
    $w->msg(5, 'خواهش!');
    ok(contains($w->bale->lastText(1), 'پاسخ ناشناس') && !contains($w->bale->lastText(1), 'U5'), 'the visitor can answer back, still anonymous');
    $w->cb(6, "ix:r:{$msgId}");
    $w->msg(6, 'hack');
    ok($w->count('SELECT COUNT(*) FROM {anonymous_inbox}') === 3, 'others cannot reply to someone else\'s message');
});

test('Inbox: blocks are silent, chats are never mixed, link can be renewed', function () {
    $w = new World();
    $owner = $w->ready(1, 'Owner');
    $w->ready(2, 'Partner');
    $w->msg(5, '/start msg' . $owner['inbox_code']);
    $w->msg(5, 'اولی');
    $msgId = (int) $w->app->db->value('SELECT id FROM {anonymous_inbox}');
    $w->cb(1, "ix:b:{$msgId}");
    $w->msg(5, '/start msg' . $owner['inbox_code']);
    $before = count($w->bale->to(1));
    $w->msg(5, 'دومی');
    ok(count($w->bale->to(1)) === $before && contains($w->bale->lastText(5), 'ارسال شد'), 'blocked sender: not delivered, not told');
    ok($w->app->db->value("SELECT status FROM {anonymous_inbox} ORDER BY id DESC LIMIT 1") === 'blocked', 'kept as blocked');
    $w->cb(1, 'st:blocks');
    ok(!contains(json_encode(last($w->bale->to(1))['p'], JSON_UNESCAPED_UNICODE), 'U5') && contains(json_encode(last($w->bale->to(1))['p'], JSON_UNESCAPED_UNICODE), 'فرستنده ناشناس'), 'block list hides the inbox sender');
    // Owner in a random chat: inbox content waits, partner never gets it.
    $w->find(1);
    $w->find(2);
    $w->msg(6, '/start msg' . $owner['inbox_code']);
    $w->msg(6, 'پیام صندوق');
    ok(!in_array('پیام صندوق', $w->bale->texts(2), true), 'partner never sees inbox messages');
    ok(contains($w->bale->lastText(1), 'یک پیام ناشناس جدید داری') && !contains($w->bale->lastText(1), 'پیام صندوق'), 'owner only gets a notice');
    // A chatting visitor who opens a link: the next message goes to the inbox, not the partner.
    $w->msg(2, '/start msg' . $owner['inbox_code']);
    $w->msg(2, 'این برای صندوقه');
    ok($w->count("SELECT COUNT(*) FROM {anonymous_inbox} WHERE text_content = 'این برای صندوقه'") === 1, 'went to the inbox');
    ok(!in_array('این برای صندوقه', $w->bale->texts(1), true), 'not relayed as a chat message');
    $w->msg(1, Ui::END);
    $w->cb(1, 'ib:new');
    ok(contains(implode("\n", $w->bale->texts(1)), 'پیام صندوق'), 'waiting messages shown from the inbox');
    $old = $owner['inbox_code'];
    $w->cb(1, 'ib:regy');
    $w->msg(7, '/start msg' . $old);
    ok(contains($w->bale->lastText(7), 'معتبر نیست'), 'old link stops working');
});

echo "\nAdmin and router\n";

test('Admin commands are admin-only', function () {
    $w = new World();
    $w->ready(1, 'Alpha');
    $w->msg(1, '/set daily_free_chats 99');
    ok($w->app->settings->int('daily_free_chats') === 5, 'normal user cannot change settings');
    $w->cb(1, 'ar:1:sp');
    $w->msg(9000, '/set daily_free_chats 7');
    ok($w->app->settings->int('daily_free_chats') === 7 && contains($w->bale->lastText(9000), '= 7'), 'admin can');
    $w->msg(9000, '/set daily_free_chats lots');
    ok(contains($w->bale->lastText(9000), 'عدد'), 'validated');
    $w->msg(9000, '/stats');
    ok(contains($w->bale->lastText(9000), 'آمار هم‌صحبت'), 'stats');
    $w->msg(9000, '/suspend Alpha 2');
    ok($w->user(1)['status'] === 'suspended', 'suspend by nickname');
    $w->msg(9000, '/unsuspend Alpha');
    ok($w->user(1)['status'] === 'active', 'unsuspend');
    $w->msg(9000, '/maintenance on');
    $w->find(1);
    ok(contains($w->bale->lastText(1), 'به‌روزرسانی'), 'maintenance mode');
});

test('Every admin command runs', function () {
    $w = new World(['rematch_cooldown_minutes' => 0]);
    $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->find(1);
    $w->find(2);
    $chat = $w->activeChat(1)['chat_id'];
    $w->msg(1, 'سلام');
    $w->cb(2, "rr:c:{$chat}:msg");
    $rid = (int) $w->app->db->value('SELECT id FROM {reports}');
    $w->bale->members[FakeBale::BOT_ID] = 'administrator';
    $expect = [
        '/admin' => 'دستورات ادمین', '/settings' => 'daily_free_chats', '/reports' => "#{$rid}",
        "/report {$rid}" => 'سلام', '/user Alpha' => 'آیدی بله: 1', '/user #2' => 'Beta', '/credit Alpha 3' => '3 اعتبار',
        '/unlock 2' => 'بدون دعوت', '/errors' => 'خطا', '/cleanup' => 'انجام شد', '/feature inbox off' => 'feature_inbox = 0',
        '/setchannel @hamsohbat https://ble.ir/hamsohbat' => 'درست تنظیم شده', '/channeltest' => 'درست تنظیم شده', '/setchannel off' => 'لازم نیست',
        '/user nobody' => 'پیدا نشد', '/report 999' => 'پیدا نشد',
    ];
    foreach ($expect as $cmd => $needle) {
        $w->msg(9000, $cmd);
        ok(contains($w->bale->lastText(9000), $needle), "{$cmd} → " . mb_substr($w->bale->lastText(9000), 0, 80));
    }
    ok($w->app->quota()->status((int) $w->user(1)['id'])['credits'] === 3, 'credit given');
    $w->cb(9000, "ar:{$rid}:s24");
    ok($w->user(1)['status'] === 'suspended' && $w->app->db->value('SELECT status FROM {reports}') === 'resolved', 'review button suspends and closes');
    ok($w->count('SELECT COUNT(*) FROM {error_logs}') === 0, 'no errors logged');
});

test('Settings, inbox history, my profile with photo', function () {
    $w = new World();
    $w->ready(1, 'Owner');
    $w->app->db->run("UPDATE {users} SET photo_file_id = 'PIC' WHERE bale_user_id = 1");
    $w->msg(1, Ui::MY_PROFILE);
    $last = last($w->bale->calls);
    ok($last['method'] === 'sendPhoto' && contains($last['p']['caption'], 'پروفایل من') && contains($last['p']['caption'], 'تاریخ تولد'), 'own profile with photo and birth date');
    $w->msg(1, Ui::SETTINGS);
    $w->cb(1, 'st:pref');
    $w->cb(1, 'st:pg:f');
    ok($w->user(1)['preferred_gender'] === 'f', 'partner gender changed in settings');
    $w->cb(1, 'st:t:notify');
    ok($w->user(1)['notify_profile_views'] == 0, 'view notifications off');
    $w->cb(1, 'st:blocks');
    ok(contains($w->bale->lastText(1), 'کسی رو مسدود نکردی'), 'empty block list');
    $w->msg(5, '/start msg' . $w->user(1)['inbox_code']);
    $w->msg(5, 'یک');
    $w->cb(1, 'ib:rec');
    ok(contains($w->bale->lastText(1), 'یک'), 'recent inbox messages shown again');
    $w->cb(1, 'ib:new');
    ok(contains(json_encode(last($w->bale->of('answerCallbackQuery'))['p'], JSON_UNESCAPED_UNICODE), 'پیام جدیدی نداری'), 'nothing new');
    $w->msg(1, Ui::INVITE);
    ok(contains(implode("\n", $w->bale->texts(1)), '?start=ref' . $w->user(1)['referral_code']), 'invite link');
});

test('A user who blocks the bot leaves their chat', function () {
    $w = new World();
    $a = $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->find(1);
    $w->find(2);
    $w->handle(['update_id' => 999, 'my_chat_member' => ['chat' => ['id' => 2, 'type' => 'private'], 'from' => ['id' => 2], 'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 1]]]]);
    ok($w->activeChat(1) === null && contains(implode("\n", $w->bale->texts(1)), 'از ربات خارج شد'), 'partner told');
});

test('Callbacks are always answered, even unknown or failing ones', function () {
    $w = new World();
    $w->ready(1, 'Alpha');
    $n = count($w->bale->of('answerCallbackQuery'));
    $w->cb(1, 'nonsense:1');
    $w->cb(1, 'ch:nx:999999');
    $w->cb(1, str_repeat('x', 100));
    ok(count($w->bale->of('answerCallbackQuery')) === $n + 3, 'three answers');
    ok(end($w->bale->calls)['method'] === 'answerCallbackQuery', 'nothing else sent for junk');
});

test('Groups, channels and bots are ignored', function () {
    $w = new World();
    $w->handle(['update_id' => 1, 'message' => ['message_id' => 1, 'from' => ['id' => 50], 'chat' => ['id' => -100, 'type' => 'group'], 'text' => '/start']]);
    $w->handle(['update_id' => 2, 'message' => ['message_id' => 2, 'from' => ['id' => 51, 'is_bot' => true], 'chat' => ['id' => 51, 'type' => 'private'], 'text' => '/start']]);
    ok($w->count('SELECT COUNT(*) FROM {users}') === 0 && !$w->bale->calls, 'nothing happened');
});

test('Idle chats end automatically', function () {
    $w = new World(['chat_idle_minutes' => 30]);
    $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->find(1);
    $w->find(2);
    $w->time += 31 * 60;
    $w->app->maintenance()->tick();
    ok($w->activeChat(1) === null && contains($w->bale->lastText(2), 'بی‌فعالیتی'), 'ended and told');
});

echo "\nv1.1: date picker, unlimited, metrics, admin panel\n";

test('Birth date picker: year → month → day, no empty buttons, typing still works', function () {
    $w = new World();
    $w->msg(1, '/start');
    $w->msg(1, Ui::COMPLETE_PROFILE);
    $w->msg(1, 'سارا');
    $w->msg(1, 'Sara');
    $ask = last($w->bale->to(1));
    $rows = $ask['p']['reply_markup']['inline_keyboard'] ?? [];
    $labels = array_merge(...array_map(fn ($r) => array_column($r, 'text'), $rows));
    ok(contains($ask['p']['text'], 'سال تولدت') && count($labels) >= 12 && !in_array('', array_map('trim', $labels), true), 'year buttons with labels');
    ok(($rows[0][0]['callback_data'] ?? '') === 'bd:y:1386', 'newest allowed year first (1404 − 18; the test clock is Esfand 1404)');
    $w->cb(1, 'bd:yp:1375');
    ok(contains(json_encode(last($w->bale->of('editMessageText'))['p'] ?? [], JSON_UNESCAPED_UNICODE), 'bd:y:1375'), 'paging edits the same message');
    $w->cb(1, 'bd:y:1390');
    ok(last($w->bale->of('answerCallbackQuery'))['p']['text'] === '' && last($w->bale->calls)['method'] === 'answerCallbackQuery', 'too-young year is ignored');
    $w->cb(1, 'bd:y:1380');
    $m = last($w->bale->of('editMessageText'));
    ok(contains($m['p']['text'], 'ماه تولدت') && count($m['p']['reply_markup']['inline_keyboard']) === 5, '12 months + back');
    $w->cb(1, 'bd:m:1380:12');
    $days = array_merge(...array_map(fn ($r) => array_column($r, 'callback_data'), last($w->bale->of('editMessageText'))['p']['reply_markup']['inline_keyboard']));
    ok(in_array('bd:d:1380:12:29', $days, true) && !in_array('bd:d:1380:12:30', $days, true), 'Esfand 1380 has 29 days');
    $w->cb(1, 'bd:m:1379:12');
    $days = array_merge(...array_map(fn ($r) => array_column($r, 'callback_data'), last($w->bale->of('editMessageText'))['p']['reply_markup']['inline_keyboard']));
    ok(in_array('bd:d:1379:12:30', $days, true), 'leap Esfand 1379 has 30 days');
    $w->cb(1, 'bd:m:1380:5');
    $w->cb(1, 'bd:d:1380:5:12');
    ok($w->user(1)['birth_date'] === '2001-08-03', 'saved 1380/05/12');
    ok(contains(implode("\n", $w->bale->texts(1)), 'تاریخ تولد: ۱۳۸۰/۰۵/۱۲') && contains($w->bale->lastText(1), 'جنسیتت'), 'confirmed and moved on');
    $w->cb(1, 'bd:d:1380:5:13');
    ok($w->user(1)['birth_date'] === '2001-08-03', 'an old picker cannot change it later');
    $w->cb(1, 'rg:g:f');
    $w->cb(1, 'rg:p:any');
    $w->cb(1, 'pe:birth');
    $w->msg(1, '1379/01/01');
    ok($w->user(1)['birth_date'] === '2000-03-20', 'typing still works when editing');
});

test('Birth date picker follows the min_age setting exactly (also below 13)', function () {
    $w = new World();
    $w->app->settings->set('min_age', '10', $w->time); // saved from the panel
    $w->msg(1, '/start');
    $w->msg(1, Ui::COMPLETE_PROFILE);
    $w->msg(1, 'سارا');
    $w->msg(1, 'Sara');
    $rows = last($w->bale->to(1))['p']['reply_markup']['inline_keyboard'] ?? [];
    ok(($rows[0][0]['callback_data'] ?? '') === 'bd:y:1394', 'newest year is 1404 − 10');
    $w->cb(1, 'bd:y:1395');
    ok(last($w->bale->calls)['method'] === 'answerCallbackQuery', 'a year past the setting is ignored');
    $w->cb(1, 'bd:y:1394');
    $w->cb(1, 'bd:m:1394:1');
    $w->cb(1, 'bd:d:1394:1:1');
    ok($w->user(1)['birth_date'] === '2015-03-21', 'a 10-year-old can pick their date');
    $w->cb(1, 'rg:g:f');
    $w->cb(1, 'rg:p:any');
    $w->app->settings->set('min_age', '12', $w->time);
    $w->cb(1, 'pe:birth');
    $w->msg(1, '1394/01/01');
    ok(contains($w->bale->lastText(1), 'حداقل ۱۲ سال') && $w->user(1)['birth_date'] === '2015-03-21', 'typed dates are checked against the new setting');
});

test('Unlimited chats gift and "refill today"', function () {
    $w = new World(['daily_free_chats' => 1, 'rematch_cooldown_minutes' => 0]);
    $a = $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->app->quota()->setUnlimited((int) $a['id'], true, $w->time + 86400);
    for ($i = 0; $i < 3; $i++) {
        $w->find(1);
        $w->app->quota()->resetToday((int) $w->user(2)['id']);
        $w->find(2);
        ok($w->activeChat(1) !== null, 'unlimited chat ' . ($i + 1));
        $w->msg(1, Ui::END);
    }
    $s = $w->app->quota()->status((int) $a['id']);
    ok($s['unlimited'] && $s['used'] === 3 && $s['credits'] === 0, 'counted but not limited, no credits spent');
    $w->time += 2 * 86400;
    ok(!$w->app->quota()->status((int) $a['id'])['unlimited'], 'expires');
    $w->app->quota()->setUnlimited((int) $a['id'], true);
    ok($w->app->quota()->status((int) $a['id'])['unlimited'], 'forever');
    $w->msg(1, Ui::MY_PROFILE);
    ok(contains($w->bale->lastText(1), 'نامحدود'), 'shown in my profile');
});

test('Metrics: per-minute aggregation and series', function () {
    $w = new World();
    Hamsobat\Metrics::record($w->app, 'webhook', 120.0);
    Hamsobat\Metrics::record($w->app, 'webhook', 1500.0, true);
    $w->time += 3600;
    Hamsobat\Metrics::record($w->app, 'webhook', 80.0);
    $s = Hamsobat\Metrics::summary($w->app, 120);
    ok($s['requests'] === 3 && $s['max_ms'] === 1500 && $s['slow'] === 1 && $s['errors'] === 1 && abs($s['avg_ms'] - 566.67) < 1, 'summary');
    $series = Hamsobat\Metrics::series($w->app, 24, 60);
    ok(count($series) === 24 && array_sum(array_column($series, 'requests')) === 3 && end($series)['requests'] === 1, 'hourly series');
});

/** A world with users, chats, messages, inbox, reports for the panel tests. */
function panel_world(): World
{
    $w = new World(['rematch_cooldown_minutes' => 0]);
    $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta', 'f');
    $w->ready(3, 'Gamma', 'f');
    $w->app->db->run("UPDATE {users} SET city = 'تهران', photo_file_id = 'PIC' WHERE bale_user_id = 2");
    $w->find(1);
    $w->find(2);
    $w->msg(1, 'سلام <b>بتا</b>');
    $w->media(2, 'photo', [['file_id' => 'PH', 'file_size' => 10]], 'عکس');
    $w->media(2, 'voice', ['file_id' => 'VO', 'duration' => 3]);
    $w->msg(1, 'آیدیم @alpha_x');
    $chat = $w->activeChat(1)['chat_id'];
    $w->cb(2, "rr:c:{$chat}:har");
    $w->msg(1, Ui::END);
    $w->msg(3, '/start msg' . $w->user(1)['inbox_code']);
    $w->msg(3, 'پیام ناشناس');
    $w->find(1);
    $w->find(3);
    Hamsobat\Metrics::record($w->app, 'webhook', 90.0);
    return $w;
}

test('Panel data: every query runs on real data', function () {
    $w = panel_world();
    $d = new Hamsobat\PanelData($w->app);
    $k = $d->kpis();
    ok($k['users'] === 3 && $k['chats_today'] === 2 && $k['messages_today'] === 3 && $k['filtered_today'] === 1 && $k['open_reports'] === 1 && $k['chatting'] === 1, 'kpis');
    $daily = $d->daily(30);
    ok(count($daily) === 30 && end($daily)['chats'] === 2 && end($daily)['users'] === 3, 'daily series ends today');
    ok(array_sum(array_map('array_sum', $d->heatmap(28))) === 3, 'heatmap counts sent messages');
    $demo = $d->demographics();
    ok($demo['genders'] === ['m' => 1, 'f' => 2] && $demo['ages']['۲۶–۳۰'] === 3 && $demo['cities'][0]['label'] === 'تهران', 'demographics');
    $top = $d->topUsers();
    ok(count($top) === 3 && $top[0]['nickname'] === 'Alpha' && (int) $top[0]['chats'] === 2, 'top users');
    [$rows, $total] = $d->users(['q' => 'bet'], 1);
    ok($total === 1 && $rows[0]['nickname'] === 'Beta', 'search by nickname');
    [$rows] = $d->users(['q' => '#' . $w->user(3)['id']], 1);
    ok(($rows[0]['nickname'] ?? '') === 'Gamma', 'search by #id');
    foreach (['seen', 'chats', 'messages', 'invites', 'credits', 'old'] as $sort) {
        foreach (['', 'online', 'incomplete', 'suspended', 'unlocked', 'unlimited'] as $status) {
            $d->users(['sort' => $sort, 'status' => $status, 'gender' => 'f'], 1);
        }
    }
    ok(true, 'every sort and filter runs');
    $u = $d->user((int) $w->user(1)['id']);
    ok(count($u['chats']) === 2 && $u['messages'] === 1 && count($u['reports_against']) === 1 && $u['inbox_in'] === 1 && $u['active_chat'] !== null, 'user detail');
    [$chats, $ct] = $d->chats(['status' => 'active'], 1);
    ok($ct === 1 && $chats[0]['status'] === 'active', 'active chats');
    [$reported] = $d->chats(['reported' => 1], 1);
    $c = $d->chat((int) $reported[0]['id']);
    ok(count($d->messages((int) $c['id'])) === 4 && count($c['reports']) === 1, 'transcript with the filtered message');
    $feed = $d->feed(0);
    ok(count($feed) === 4 && $feed[0]['s_nick'] === 'Alpha' && $feed[0]['r_nick'] === 'Beta', 'live feed with both nicknames');
    [$inbox] = $d->inbox(1);
    $t = $d->thread((int) $inbox[0]['session_id']);
    ok($inbox[0]['s_nick'] === 'Gamma' && count($t['messages']) === 1, 'inbox + thread');
    [$reports] = $d->reports('open', 1);
    ok(count($reports) === 1 && count($reports[0]['excerpt']) === 4, 'reports with excerpt');
    ok(is_array($d->errors()) && is_array($d->audit()) && $d->live()['chatting'] === 1, 'logs and live');
    $info = new Hamsobat\ServerInfo($w->app);
    $db = $info->database();
    ok(count($db['tables']) === 25 && $db['size'] > 0, 'server info: 25 tables');
    ok(isset($info->system()['cpus'], $info->php()['version']), 'system + php');
});

test('Panel actions: gifts, unlock, moderation, settings, all audited', function () {
    $w = panel_world();
    $act = new Hamsobat\PanelActions($w->app, new Hamsobat\PanelAuth($w->app));
    $alpha = (int) $w->user(1)['id'];
    $beta = (int) $w->user(2)['id'];
    ok($act->run('credit', ['user_id' => $alpha, 'amount' => 500, 'notify' => 1])[0] && $w->app->quota()->status($alpha)['credits'] === 500, '+500 credits');
    ok(contains($w->bale->lastText(1), '۵۰۰ چت اضافه'), 'user told');
    ok(!$act->run('credit', ['user_id' => $alpha, 'amount' => 0])[0], 'zero refused');
    ok($act->run('unlimited', ['user_id' => $beta, 'days' => 7])[0] && $w->app->quota()->status($beta)['unlimited'], 'unlimited 7 days');
    ok($act->run('unlimited', ['user_id' => $beta, 'days' => -1])[0] && !$w->app->quota()->status($beta)['unlimited'], 'unlimited removed');
    ok($act->run('unlock', ['user_id' => $beta, 'on' => 1])[0] && $w->user(2)['referral_waived'] == 1, 'unlocked');
    ok($act->run('nickname', ['user_id' => $beta, 'nickname' => 'Alpha'])[0] === false, 'nickname must stay unique');
    ok($act->run('nickname', ['user_id' => $beta, 'nickname' => 'Bettie'])[0] && $w->user(2)['nickname'] === 'Bettie', 'renamed');
    ok($act->run('clear', ['user_id' => $beta, 'field' => 'photo'])[0] && $w->user(2)['photo_file_id'] === null, 'photo cleared');
    ok($act->run('message', ['user_id' => $beta, 'text' => 'سلام از مدیر'])[0] && contains($w->bale->lastText(2), 'سلام از مدیر'), 'message sent');
    ok($act->run('end_user_chat', ['user_id' => $alpha])[0] && $w->activeChat(1) === null && contains($w->bale->lastText(3), 'مدیریت'), 'chat ended, both told');
    ok($act->run('suspend', ['user_id' => $beta, 'hours' => 24])[0] && $w->user(2)['status'] === 'suspended', 'suspended');
    ok($act->run('unsuspend', ['user_id' => $beta])[0] && $w->user(2)['status'] === 'active', 'unsuspended');
    $rid = (int) $w->app->db->value('SELECT id FROM {reports}');
    ok($act->run('report', ['report_id' => $rid, 'status' => 'resolved'])[0] && $w->app->db->value('SELECT status FROM {reports}') === 'resolved', 'report resolved');
    $r = $act->run('settings', ['s' => ['daily_free_chats' => '۷', 'required_channel' => 'bad channel', 'allowed_types' => ['text', 'photo', 'voice'], 'feature_inbox' => '1', 'block_links' => '1', 'store_message_text' => '1', 'require_channel' => '1', 'referral_requires_channel' => '1', 'notify_admin_reports' => '1', 'feature_media' => '1']]);
    ok(!$r[0] && contains($r[1], 'required_channel') && $w->app->settings->int('daily_free_chats') === 7, 'valid settings saved (Persian digits ok), invalid one reported');
    ok($w->app->settings->str('allowed_types') === 'text,photo,voice' && !$w->app->settings->bool('feature_profile_view_notify'), 'checkbox list saved, unchecked switch = off');
    ok(!$act->run('credit', ['user_id' => 99999, 'amount' => 5])[0], 'unknown user');
    ok($w->count('SELECT COUNT(*) FROM {admin_log}') === 14, 'every action on a real user audited (14)');
});

test('Who to talk to is asked before every search', function () {
    $w = new World(['rematch_cooldown_minutes' => 0]);
    $a = $w->ready(1, 'Alpha', 'm', 'any');
    $w->ready(2, 'Beta', 'f', 'any');
    $w->ready(3, 'Gamma', 'm', 'any');
    $w->msg(1, Ui::FIND);
    $ask = last($w->bale->to(1));
    ok(contains($ask['p']['text'], 'این بار با کی') && !$w->app->matching()->isSearching((int) $a['id']), 'asked, not searching yet');
    ok(($ask['p']['reply_markup']['inline_keyboard'][1][0]['text'] ?? '') === '🎲 فرقی نمی‌کنه ✓', 'last choice marked');
    $w->cb(1, 'fg:f');
    ok($w->user(1)['preferred_gender'] === 'f' && $w->app->matching()->isSearching((int) $a['id']), 'girl chosen, searching');
    ok(contains(implode("\n", $w->bale->texts(1)), 'هم‌صحبت: 👩 دختر ✅'), 'choice confirmed in the same message');
    $w->find(3, 'any');
    ok($w->activeChat(3) === null, 'a boy is not matched with someone who chose girl');
    $w->msg(3, Ui::CANCEL_SEARCH);
    $w->find(2, 'any');
    ok(($w->activeChat(1)['partner_id'] ?? 0) === (int) $w->user(2)['id'], 'matched with a girl');
    $w->msg(1, Ui::END);
    $w->msg(1, Ui::FIND);
    ok(contains($w->bale->lastText(1), 'این بار با کی') && contains(json_encode(last($w->bale->to(1))['p'], JSON_UNESCAPED_UNICODE), '👩 دختر ✓'), 'asked again next time, girl marked');
    $w->cb(1, 'fg:x');
    ok(!$w->app->matching()->isSearching((int) $a['id']) && $w->app->quota()->status((int) $a['id'])['free_left'] === 4, 'cancel: nothing searched, nothing spent');
    $w->find(1, 'any');
    $w->msg(1, Ui::FIND);
    ok(contains($w->bale->lastText(1), 'هنوز داریم می‌گردیم'), 'pressing 🔎 while searching does not ask again');
});

echo "\nv1.2: real Bale account (admin only)\n";

test('Bale name and @username are saved, changes kept, never shown to other users', function () {
    $w = new World();
    $w->from[1] = ['first_name' => 'Ali', 'last_name' => 'Rezaei', 'username' => 'ali_real'];
    $w->from[2] = ['first_name' => 'Sara', 'username' => 'sara_real'];
    $a = $w->ready(1, 'Alpha');
    $b = $w->ready(2, 'Beta', 'f');
    $acc = $w->app->users()->accounts([$a['id'], $b['id']]);
    ok(($acc[$a['id']]['username'] ?? '') === 'ali_real' && $acc[$a['id']]['first_name'] === 'Ali' && $acc[$a['id']]['last_name'] === 'Rezaei', 'saved on first contact');
    // Changes are picked up (at most once a minute) and the old identity is kept.
    $w->from[1] = ['first_name' => 'Ali', 'username' => 'ali_new'];
    $w->msg(1, Ui::MY_PROFILE);
    ok($w->app->users()->accounts([$a['id']])[$a['id']]['username'] === 'ali_real', 'not re-written within a minute');
    $w->time += 61;
    $w->msg(1, Ui::MY_PROFILE);
    $now = $w->app->users()->accounts([$a['id']])[$a['id']];
    ok($now['username'] === 'ali_new' && $now['last_name'] === null && $now['previous_names'] === '@ali_real · Ali Rezaei', 'updated, previous name kept');
    // Nothing of it ever reaches another user: match card, profile view, relay, inbox.
    $w->find(1);
    $w->find(2);
    $w->msg(2, Ui::VIEW_PROFILE);
    $w->msg(1, 'سلام');
    $w->msg(2, '/start msg' . $w->user(1)['inbox_code']);
    $w->msg(2, 'ناشناس');
    $w->msg(1, Ui::END);
    $w->cb(1, 'ib:new');
    $toB = implode("\n", $w->bale->texts(2));
    $toA = implode("\n", $w->bale->texts(1));
    foreach (['Ali', 'Rezaei', 'ali_real', 'ali_new'] as $leak) {
        ok(!str_contains($toB, $leak), "partner never sees {$leak}");
    }
    foreach (['Sara', 'sara_real'] as $leak) {
        ok(!str_contains($toA, $leak), "inbox owner never sees {$leak}");
    }
    ok(contains($toA, 'نام و آیدی حساب بله') || contains(Ui::guide(5, 3, 7), 'نام و آیدی حساب بله'), 'the guide tells users the admin can see it');
});

test('Live update: works before migrate.php, then old users are backfilled', function () {
    $w = new World();
    $w->app->db->pdo->exec('DROP TABLE t_user_accounts');
    $w->from[1] = ['first_name' => 'Old', 'username' => 'old_user'];
    $a = $w->ready(1, 'Alpha');
    $w->ready(2, 'Beta');
    $w->find(1);
    $w->find(2);
    $w->msg(1, 'سلام');
    ok($w->activeChat(2) !== null && $w->bale->lastText(2) === 'سلام', 'bot works without the new table');
    ok($w->count("SELECT COUNT(*) FROM {error_logs}") === 0, 'no errors logged');
    $d = new Hamsobat\PanelData($w->app);
    ok($d->user((int) $a['id'])['account'] === null && $d->users(['q' => 'old_user'], 1)[1] === 0, 'panel works without it too');
    // migrate.php runs while the bot is live
    foreach (Hamsobat\Database::schema(dirname(__DIR__) . '/database.sql', 't_') as $sql) {
        $w->app->db->pdo->exec($sql);
    }
    $w2 = new World(); // a new request = a fresh table check (same tables, emptied)
    $w2->from[1] = ['first_name' => 'Old', 'username' => 'old_user'];
    $a = $w2->ready(1, 'Alpha');
    $w2->app->db->run('DELETE FROM {user_accounts}');
    $w2->ready(2, 'Beta');
    $w2->ready(3, 'Gamma');
    $w2->app->db->run('DELETE FROM {user_accounts} WHERE user_id <> ?', [$a['id']]);
    $w2->bale->chats[2] = ['first_name' => 'Bea', 'username' => 'bea_bale'];
    ok($w2->app->users()->backfillAccounts(30) === 3, 'three users without account looked up');
    $acc = $w2->app->users()->accounts([$w2->user(1)['id'], $w2->user(2)['id'], $w2->user(3)['id']]);
    ok($acc[$w2->user(2)['id']]['username'] === 'bea_bale' && $acc[$w2->user(2)['id']]['source'] === 'getChat', 'filled from getChat');
    ok($acc[$w2->user(3)['id']]['source'] === 'none' && $w2->app->users()->backfillAccounts(30) === 0, 'failed lookup not repeated');
    $w2->from[3] = ['first_name' => 'Gam', 'username' => 'gam_bale'];
    $w2->time += 61;
    $w2->msg(3, Ui::MY_PROFILE);
    ok($w2->app->users()->accounts([$w2->user(3)['id']])[$w2->user(3)['id']]['username'] === 'gam_bale', 'the next message fills it');
});

test('Panel: search by @username or real name, refresh from Bale', function () {
    $w = new World();
    $w->from[1] = ['first_name' => 'Mahsa', 'last_name' => 'Karimi', 'username' => 'mahsa_k'];
    $u = $w->ready(1, 'Moon', 'f');
    $d = new Hamsobat\PanelData($w->app);
    foreach (['mahsa_k', 'Karimi', 'Mahsa'] as $q) {
        [$rows, $total] = $d->users(['q' => $q], 1);
        ok($total === 1 && ($rows[0]['account']['username'] ?? '') === 'mahsa_k', "found by {$q}");
    }
    ok(contains(Hamsobat\Html::account($rows[0]['account']), 'https://ble.ir/mahsa_k'), 'links to the Bale profile');
    $act = new Hamsobat\PanelActions($w->app, new Hamsobat\PanelAuth($w->app));
    $w->bale->chats[1] = ['first_name' => 'Mahsa', 'username' => 'mahsa_new'];
    $r = $act->run('fetch_account', ['user_id' => $u['id']]);
    ok($r[0] && contains($r[1], '@mahsa_new') && contains($w->app->users()->accounts([$u['id']])[$u['id']]['previous_names'], 'mahsa_k'), 'refresh from Bale');
    $w->msg(9000, '/user Moon');
    ok(contains($w->bale->lastText(9000), '@mahsa_new'), 'admin /user command shows it');
});

echo "\nv1.3: channel diagnosis, broadcast\n";

test('Channel: "no such group or user" for a non-member is not a setup error when the bot is admin', function () {
    $w = new World(['require_channel' => 1, 'required_channel' => '@log_channel', 'channel_link' => 'https://ble.ir/log_channel', 'required_referrals' => 0]);
    $w->bale->notMemberError = 'Bad Request: no such group or user';
    $w->bale->members[FakeBale::BOT_ID] = 'administrator';
    $w->bale->chats['@log_channel'] = ['title' => 'لاگ', 'username' => 'log_channel'];
    $w->bale->chats['-1001234'] = ['title' => 'لاگ', 'username' => 'log_channel'];
    $u = $w->register(5, 'Moon');
    $w->msg(5, Ui::FIND);
    ok(contains($w->bale->lastText(5), 'عضو کانال شو'), 'non-member sees the join step');
    ok(!$w->bale->texts(9000), 'admin is not alerted: setup is fine');
    ok($w->count("SELECT COUNT(*) FROM {error_logs}") === 0, 'nothing logged');
    $w->bale->members[5] = 'member';
    $w->cb(5, 'rq');
    ok(contains($w->bale->lastText(5), 'همه‌چیز آماده‌ست'), 'member passes');
});

test('Channel: bot not admin, or channel not found → clear instructions to the admin', function () {
    $w = new World(['require_channel' => 1, 'required_channel' => '@log_channel', 'required_referrals' => 0]);
    $w->bale->notMemberError = 'Bad Request: no such group or user';
    $w->bale->members[FakeBale::BOT_ID] = 'member';
    $w->register(5, 'Moon');
    $w->msg(5, Ui::FIND);
    ok(contains(implode("\n", $w->bale->texts(9000)), 'ادمین نیست'), 'bot is a member but not admin');
    $w2 = new World(['require_channel' => 1, 'required_channel' => '@log_channel', 'required_referrals' => 0]);
    $w2->bale->notMemberError = 'Bad Request: no such group or user';
    $w2->register(5, 'Moon');
    $w2->msg(5, Ui::FIND);
    $alert = implode("\n", $w2->bale->texts(9000));
    ok(contains($alert, 'پیدا نمی‌کند') && contains($alert, 'فوروارد'), 'not found: steps incl. forwarding a post');
});

test('Channel: forward a post → one-tap setup with the numeric id; bot added to a channel is noticed', function () {
    $w = new World(['required_referrals' => 0]);
    $w->bale->chats['-1007788'] = ['title' => 'هم‌صحبت', 'username' => 'hamsohbat_ch'];
    $w->bale->members[FakeBale::BOT_ID] = 'administrator';
    $w->msg(9000, '/start');
    $w->msg(9000, '', ['forward_from_chat' => ['id' => -1007788, 'type' => 'channel', 'title' => 'هم‌صحبت', 'username' => 'hamsohbat_ch']]);
    $last = last($w->bale->to(9000));
    ok(contains($last['p']['text'], '-1007788') && ($last['p']['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? '') === 'ac:set:-1007788', 'id shown with a button');
    $w->cb(9000, 'ac:set:-1007788');
    $s = $w->app->settings;
    ok($s->str('required_channel') === '@hamsohbat_ch' && $s->str('channel_link') === 'https://ble.ir/hamsohbat_ch' && $s->bool('require_channel'), 'channel and link set');
    ok($w->app->requirements()->channelTarget() === '-1007788', 'numeric id used for checks');
    ok(contains($w->bale->lastText(9000), 'درست تنظیم شده'), 'diagnosis ok');
    $w->cb(5, 'ac:set:-1007788');
    ok($w->user(5) === null || $s->str('required_channel') === '@hamsohbat_ch', 'non-admins cannot press it');
    $w->handle(['update_id' => 77, 'my_chat_member' => ['chat' => ['id' => -1009999, 'type' => 'channel', 'title' => 'کانال دوم'], 'from' => ['id' => 9000], 'new_chat_member' => ['status' => 'administrator', 'user' => ['id' => 1]]]]);
    ok(contains($w->bale->lastText(9000), 'کانال دوم') && (string) ($w->app->requirements()->knownChannels()[0]['id'] ?? '') === '-1009999', 'admin told, channel remembered');
});

test('Broadcast: everyone once, suspended skipped, failures counted, pause/resume/cancel', function () {
    $w = new World();
    foreach (range(1, 7) as $i) {
        $w->ready($i, 'User' . $i);
    }
    $w->app->moderation()->suspend((int) $w->user(3)['id'], 0);
    $w->bale->unreachable = [5];
    $b = $w->app->broadcaster();
    $id = $b->create("سلام همه 👋\nخبر خوب!", 'all', '🎁 ببین', 'https://ble.ir/x');
    ok(is_int($id) && $w->count('SELECT total FROM {broadcasts}') === 6, 'total excludes the suspended user');
    $before = count($w->bale->calls);
    $b->step(5.0, 2);
    $sends = array_values(array_filter(array_slice($w->bale->calls, $before), fn ($c) => $c['method'] === 'sendMessage' && ($c['p']['text'] ?? '') === "سلام همه 👋\nخبر خوب!"));
    $to = array_map(fn ($c) => (int) $c['p']['chat_id'], $sends);
    sort($to);
    ok($to === [1, 2, 4, 5, 6, 7], 'each active user exactly once (batches of 2)');
    ok(($sends[0]['p']['reply_markup']['inline_keyboard'][0][0]['url'] ?? '') === 'https://ble.ir/x', 'with the link button');
    $r = $w->app->db->row('SELECT * FROM {broadcasts}');
    ok($r['status'] === 'done' && (int) $r['sent'] === 5 && (int) $r['failed'] === 1, '5 sent, 1 blocked the bot, done');
    $b->step(1.0);
    ok(count(array_filter($w->bale->calls, fn ($c) => ($c['p']['text'] ?? '') === "سلام همه 👋\nخبر خوب!")) === 6, 'nothing sent twice');
    $id2 = $b->create('دوم', 'girls');
    ok(is_string($b->create('', 'all')) && is_string($b->create('x', 'all', 'btn', 'http://no')), 'validation');
    $b->setStatus($id2, 'paused');
    ok($b->step(1.0)['sent'] === 0, 'paused: nothing sent');
    ok($b->setStatus($id2, 'running') && $b->setStatus($id2, 'cancelled') && !$b->setStatus($id2, 'running'), 'resume, cancel, cannot restart a cancelled one');
});

test('Broadcast moves on during webhooks; panel actions: test, send to one', function () {
    $w = new World();
    foreach (range(1, 3) as $i) {
        $w->ready($i, 'User' . $i);
    }
    $w->from[2] = ['username' => 'two_bale'];
    $w->time += 61;
    $w->msg(2, Ui::MY_PROFILE);
    $act = new Hamsobat\PanelActions($w->app, new Hamsobat\PanelAuth($w->app));
    ok($act->run('broadcast_test', ['text' => 'تست تبلیغ'])[0] && $w->bale->lastText(9000) === 'تست تبلیغ' && !in_array('تست تبلیغ', $w->bale->texts(1), true), 'test goes to admins only');
    ok($act->run('broadcast', ['text' => 'تبلیغ', 'audience' => 'all'])[0], 'broadcast created');
    $w->app->maintenance()->afterResponse();
    ok($w->bale->lastText(3) === 'تبلیغ' && $w->app->db->value('SELECT status FROM {broadcasts}') === 'done', 'sent during the webhook\'s after-work');
    foreach (['#' . $w->user(1)['id'] => 1, '@two_bale' => 2, 'User3' => 3, '1' => 1] as $to => $bale) {
        ok($act->run('send_one', ['to' => $to, 'text' => "سلام {$to}"])[0] && $w->bale->lastText($bale) === "سلام {$to}", "send to one: {$to}");
    }
    ok(!$act->run('send_one', ['to' => 'nobody', 'text' => 'x'])[0], 'unknown receiver');
});

echo "\nReferral review\n";

test('Referral: friend joins the channel later and never presses anything → still counted', function () {
    $w = new World(['require_channel' => 1, 'required_channel' => '@hamsohbat', 'referral_requires_channel' => 1, 'referral_reward_credits' => 3]);
    $w->bale->members[FakeBale::BOT_ID] = 'administrator';
    $w->bale->members[1] = 'member';
    $a = $w->register(1, 'Inviter');
    $w->msg(1, Ui::INVITE);
    $link = 'https://ble.ir/hamsohbatbot?start=ref' . $a['referral_code'];
    ok(contains(implode("\n", $w->bale->texts(1)), $link), 'invite screen shows the exact deep link');
    // The friend opens the link: Bale sends "/start ref…"; registers with the date picker (buttons only).
    $w->msg(2, '/start ref' . $a['referral_code']);
    ok(contains($w->bale->lastText(1), 'با لینک دعوتت وارد'), 'inviter told someone came');
    $w->msg(2, Ui::COMPLETE_PROFILE);
    $w->msg(2, 'دوست');
    $w->msg(2, 'Friend');
    $w->cb(2, 'bd:y:1380');
    $w->cb(2, 'bd:m:1380:5');
    $w->cb(2, 'bd:d:1380:5:12');
    $w->cb(2, 'rg:g:f');
    $w->cb(2, 'rg:p:any');
    ok($w->count("SELECT COUNT(*) FROM {referrals} WHERE status = 'pending'") === 1, 'pending: friend has not joined the channel');
    $w->bale->members[2] = 'member'; // joins later, presses nothing
    $w->time += 3700;
    $w->app->maintenance()->tick();
    ok($w->count("SELECT COUNT(*) FROM {referrals} WHERE status = 'qualified'") === 1, 'hourly cleanup counted it');
    ok($w->app->quota()->status((int) $a['id'])['credits'] === 3 && contains($w->bale->lastText(1), 'برات باز شد'), 'inviter rewarded and unlocked');
    $w->find(1);
    ok($w->app->matching()->isSearching((int) $a['id']), 'inviter can search now');
});

test('Referral: no "unlocked" message for admins or already-unlocked users', function () {
    $w = new World();
    $w->msg(9000, '/start');
    $w->msg(9000, Ui::COMPLETE_PROFILE);
    $w->msg(9000, 'ادمین');
    $w->msg(9000, 'BossMan');
    $w->msg(9000, '1370/01/01');
    $w->cb(9000, 'rg:g:m');
    $w->cb(9000, 'rg:p:any');
    $admin = $w->user(9000);
    $w->register(2, 'Friend', 'f', 'any', '1376/01/01', 'ref' . $admin['referral_code']);
    $t = implode("\n", $w->bale->texts(9000));
    ok(contains($t, 'ثبت‌نامش رو کامل کرد') && !contains($t, 'برات باز شد'), 'admin rewarded without the misleading unlock line');
});

echo "\n" . $GLOBALS['pass'] . ' passed, ' . count($GLOBALS['fail']) . " failed\n";
foreach ($GLOBALS['fail'] as $f) {
    echo "  ✘ {$f}\n";
}
exit($GLOBALS['fail'] ? 1 : 0);
