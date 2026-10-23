<?php
// Fills a TEST database with realistic demo data (users, 30 days of chats, messages,
// reports, inbox, 24 h of request metrics) to preview the admin panel.
//   HAMSOBAT_CONFIG=tests/e2e/state/config.php php tests/demo_seed.php
// Refuses to run unless the database name contains "test", "e2e" or "demo".
require dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set('UTC');

use Hamsobat\Database;
use Hamsobat\Text;

$config = hamsobat_config();
$db = Database::connect($config);
if (!preg_match('~test|e2e|demo~', (string) $db->value('SELECT DATABASE()'))) {
    exit("refusing: not a test database\n");
}
mt_srand(1405);
$now = time();
$ts = fn (int $t) => gmdate('Y-m-d H:i:s', $t);
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];

$db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['users', 'user_interests', 'referrals', 'chat_queue', 'chats', 'active_chats', 'daily_usage', 'user_credits', 'credit_ledger', 'messages',
          'anonymous_sessions', 'anonymous_inbox', 'profile_views', 'blocked_users', 'reports', 'rate_limits', 'request_metrics', 'user_perks', 'admin_log', 'error_logs', 'user_accounts', 'broadcasts'] as $t) {
    $db->pdo->exec('TRUNCATE ' . $db->t($t));
}
$db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$girls = ['نیلوفر', 'ستاره', 'مهسا', 'پرنیان', 'سارا', 'ترانه', 'آیدا', 'باران', 'نازنین', 'هستی', 'یاسمن', 'Sara', 'Yasi', 'Mina', 'رها', 'غزل', 'شیدا', 'Elena'];
$boys = ['آرش', 'سینا', 'کیان', 'امیر', 'پارسا', 'رضا', 'Arash', 'Kian', 'Matin', 'سهیل', 'نیما', 'بردیا', 'آرمان', 'Ali', 'مهرداد', 'Pouya', 'یاشار', 'دانیال'];
$nicks = ['ماه_شب', 'Moon', 'ستاره_دنباله‌دار', 'Nightowl', 'قهوه_تلخ', 'BlueSky', 'رهگذر', 'Sunflower', 'باران_پاییزی', 'Lunar', 'آسمان', 'Echo', 'خیال', 'Pixel', 'شب_پره',
    'Nova', 'دریا', 'Cactus', 'مهتاب', 'Wanderer', 'پروانه', 'Comet', 'نسیم', 'Maple', 'سایه', 'Orbit', 'آهو', 'Zephyr', 'شقایق', 'Atlas', 'گیلاس', 'Indigo', 'نارنج', 'Jade',
    'کهکشان', 'Lotus', 'رویا', 'Quartz', 'ابریشم', 'Raven', 'سپیده', 'Saffron', 'افق', 'Tango', 'یلدا', 'Velvet', 'زمستان', 'Willow', 'تابستان', 'Yuki'];
$cities = ['تهران', 'تهران', 'تهران', 'اصفهان', 'شیراز', 'مشهد', 'تبریز', 'کرج', 'رشت', 'اهواز', 'کرمان', 'یزد', 'قم', 'ساری'];
$bios = ['عاشق موسیقی و فیلم 🎬', 'دنبال یه هم‌صحبت خوب', 'دانشجوی معماری', 'قهوه، کتاب، بارون ☕️', 'فوتبالی و گیمر 🎮', 'برنامه‌نویس شب‌زنده‌دار', 'سفر رو دوست دارم ✈️', null, null];
$interests = ['موسیقی', 'فیلم', 'کتاب', 'سفر', 'فوتبال', 'گیم', 'عکاسی', 'آشپزی', 'نقاشی', 'برنامه‌نویسی', 'کوهنوردی', 'انیمه'];
$lines = ['سلام 👋', 'سلام! خوبی؟', 'مرسی تو خوبی؟', 'از کجایی؟', 'تهران، تو؟', 'منم اصفهانم 😄', 'چه خوب! چند سالته؟', 'بیست و دو', 'چه کار می‌کنی؟', 'دانشجوام',
    'رشته‌ات چیه؟', 'کامپیوتر 💻', 'جدی؟ منم!', 'چه فیلمی اخیراً دیدی؟', 'اینتراستلار رو دوباره دیدم 🌌', 'عاشقشم اون فیلمو', 'موسیقی چی گوش میدی؟', 'بیشتر پاپ و یکم راک',
    'امشب هوا خیلی خوبه', 'آره بارون میاد 🌧', 'خوشحال شدم باهات حرف زدم 😊', 'منم همینطور', 'شب بخیر 🌙', 'بای', 'هه هه 😂', 'واقعا؟', 'نه بابا', 'دقیقا!', 'چه جالب',
    'تو چی دوست داری؟', 'کتاب زیاد می‌خونم', 'آخرین کتابت چی بود؟', 'صد سال تنهایی 📚', 'عالیه'];

// ---------------------------------------------------------------- users
$ids = [];
for ($i = 1; $i <= 140; $i++) {
    $g = mt_rand(0, 100) < 46 ? 'f' : 'm';
    $created = $now - (int) (pow(mt_rand(0, 1000) / 1000, 1.7) * 30 * 86400);
    $jy = mt_rand(1368, 1386);
    [$gy, $gm, $gd] = Text::jalaliToGregorian($jy, mt_rand(1, 12), mt_rand(1, 29));
    $seen = $i <= 14 ? $now - mt_rand(0, 240) : $created + mt_rand(0, max(1, $now - $created));
    $nick = $nicks[($i - 1) % count($nicks)] . ($i > count($nicks) ? (string) $i : '');
    $complete = $i % 17 !== 0;
    $db->insert('users', [
        'bale_user_id' => 400000000 + $i * 7919, 'display_name' => $complete ? $pick($g === 'f' ? $girls : $boys) : null, 'nickname' => $complete ? $nick : null,
        'birth_date' => $complete ? sprintf('%04d-%02d-%02d', $gy, $gm, $gd) : null, 'gender' => $complete ? $g : null,
        'preferred_gender' => $complete ? $pick(['any', 'any', 'any', $g === 'f' ? 'm' : 'f']) : null, 'city' => $complete && mt_rand(0, 3) ? $pick($cities) : null,
        'bio' => $complete ? $pick($bios) : null, 'photo_file_id' => $complete && $i % 3 === 0 ? "demo-photo-{$i}" : null,
        'referral_code' => Text::code(8), 'inbox_code' => Text::code(10), 'profile_completed' => $complete ? 1 : 0,
        'referral_waived' => $i <= 3 ? 1 : 0, 'created_at' => $ts($created), 'updated_at' => $ts($created), 'last_seen_at' => $ts(min($now, $seen)),
    ]);
    $ids[$i] = (int) $db->pdo->lastInsertId();
    if ($complete) {
        foreach ((array) array_rand(array_flip($interests), mt_rand(1, 3)) as $in) {
            $db->run('INSERT IGNORE INTO {user_interests} (user_id, interest) VALUES (?, ?)', [$ids[$i], $in]);
        }
    }
}
$admin = (int) ($config['admin']['ids'][0] ?? 0);
if ($admin) {
    $db->run('UPDATE {users} SET bale_user_id = ? WHERE id = ?', [$admin, $ids[1]]);
}

// ---------------------------------------------------------------- referrals
for ($i = 20; $i <= 110; $i += 1) {
    if (mt_rand(0, 100) < 55) {
        $inviter = $ids[mt_rand(1, 19)];
        $db->insert('referrals', ['inviter_user_id' => $inviter, 'invited_user_id' => $ids[$i], 'status' => mt_rand(0, 10) ? 'qualified' : 'pending',
            'qualified_at' => $ts($now - mt_rand(0, 25) * 86400 - mt_rand(0, 86000)), 'reward_credits' => 3, 'created_at' => $ts($now - 26 * 86400)]);
        $db->run('UPDATE {users} SET referred_by_user_id = ? WHERE id = ?', [$inviter, $ids[$i]]);
    }
}
foreach ([4 => 12, 5 => 30, 7 => 3, 9 => 120] as $u => $credits) {
    $db->run('INSERT INTO {user_credits} (user_id, credit_balance, updated_at) VALUES (?, ?, ?)', [$ids[$u], $credits, $ts($now)]);
}
$db->run('INSERT INTO {user_perks} (user_id, unlimited, unlimited_until, note, updated_at) VALUES (?, 1, ?, ?, ?)', [$ids[2], $ts($now + 6 * 86400), 'demo', $ts($now)]);

// ---------------------------------------------------------------- chats + messages
$hourWeight = [2, 1, 1, 1, 1, 1, 1, 2, 3, 4, 5, 6, 6, 6, 5, 5, 6, 7, 8, 9, 10, 10, 9, 5];
$weighted = [];
foreach ($hourWeight as $h => $w) {
    $weighted = array_merge($weighted, array_fill(0, $w, $h));
}
$active = [];
$complete = array_values(array_filter($ids, fn ($id, $i) => $i % 17 !== 0, ARRAY_FILTER_USE_BOTH));
$heavy = array_slice($complete, 0, 12);
for ($c = 0; $c < 460; $c++) {
    $daysAgo = (int) floor(pow(mt_rand(0, 1000) / 1000, 1.6) * 30);
    $dayStart = strtotime(gmdate('Y-m-d', $now - $daysAgo * 86400) . ' 00:00:00 UTC') - 12600; // Tehran midnight
    $start = $dayStart + $pick($weighted) * 3600 + mt_rand(0, 3599);
    if ($start > $now - 120) {
        $start = $now - mt_rand(600, 7200);
    }
    $a = mt_rand(0, 100) < 40 ? $pick($heavy) : $pick($complete);
    do {
        $b = $pick($complete);
    } while ($b === $a);
    $isActive = $c < 6;
    if ($isActive) {
        $start = $now - mt_rand(60, 900);
        if (isset($active[$a]) || isset($active[$b])) {
            continue;
        }
    }
    $len = mt_rand(30, 1500);
    $n = $daysAgo <= 6 || $isActive ? mt_rand(2, 18) : 0;
    $chatId = $db->insert('chats', [
        'user_one_id' => $a, 'user_two_id' => $b, 'status' => $isActive ? 'active' : 'ended', 'started_at' => $ts($start),
        'ended_at' => $isActive ? null : $ts($start + $len), 'ended_by_user_id' => $isActive ? null : $pick([$a, $b]),
        'end_reason' => $isActive ? null : $pick(['end', 'end', 'next', 'next', 'next', 'idle', 'block']), 'usage_date' => gmdate('Y-m-d', $start + 12600),
        'user_one_credit' => 'free', 'user_two_credit' => 'free', 'message_count' => $n ?: mt_rand(2, 14), 'last_message_at' => $ts($start + $len),
    ]);
    if ($isActive) {
        $active[$a] = $active[$b] = true;
        $db->run('INSERT INTO {active_chats} (user_id, chat_id, partner_user_id) VALUES (?, ?, ?), (?, ?, ?)', [$a, $chatId, $b, $b, $chatId, $a]);
    }
    $t = $start + 5;
    for ($m = 0; $m < $n; $m++) {
        $t += mt_rand(4, 90);
        $sender = $m % 2 ? $b : $a;
        $type = mt_rand(0, 100) < 8 ? $pick(['photo', 'voice', 'sticker']) : 'text';
        $text = $type === 'text' ? $lines[($m + $c) % count($lines)] : ($type === 'photo' && mt_rand(0, 1) ? 'این عکس منه 😄' : null);
        $status = 'sent';
        if ($type === 'text' && mt_rand(0, 100) < 3) {
            $text = 'آیدیم رو بگیر @' . 'user' . mt_rand(100, 999);
            $status = 'filtered';
        }
        $db->insert('messages', ['chat_id' => $chatId, 'sender_user_id' => $sender, 'message_type' => $type, 'text_content' => $text,
            'bale_file_id' => $type === 'text' ? null : "demo-{$type}-{$chatId}-{$m}", 'source_message_id' => $m + 1, 'created_at' => $ts(min($now - 2, $t)), 'delivery_status' => $status]);
    }
}
foreach ($complete as $u) {
    $db->run('INSERT INTO {daily_usage} (user_id, usage_date, successful_chats) SELECT ?, ?, COUNT(*) FROM {chats} WHERE (user_one_id = ? OR user_two_id = ?) AND usage_date = ?
              ON DUPLICATE KEY UPDATE successful_chats = VALUES(successful_chats)', [$u, gmdate('Y-m-d', $now + 12600), $u, $u, gmdate('Y-m-d', $now + 12600)]);
}
foreach (array_slice($complete, 40, 3) as $u) {
    if (!isset($active[$u])) {
        $db->run("INSERT INTO {chat_queue} (user_id, preferred_gender, queued_at, expires_at, status) VALUES (?, 'any', ?, ?, 'waiting')", [$u, $ts($now - 10), $ts($now + 3600)]);
    }
}

// ---------------------------------------------------------------- reports, blocks, inbox, views
$recent = $db->all("SELECT id, user_one_id, user_two_id FROM {chats} WHERE started_at >= ? AND message_count > 3 ORDER BY id DESC LIMIT 8", [$ts($now - 5 * 86400)]);
$details = ['حرف زشت زد', 'مدام شماره می‌خواست', null, 'عکس نامناسب فرستاد', null];
foreach (array_slice($recent, 0, 5) as $k => $r) {
    $db->insert('reports', ['reporter_user_id' => $r['user_two_id'], 'reported_user_id' => $r['user_one_id'], 'chat_id' => $r['id'], 'reason' => ['msg', 'har', 'oth', 'ill', 'har'][$k],
        'details' => $details[$k], 'created_at' => $ts($now - mt_rand(600, 4 * 86400)), 'status' => $k < 4 ? 'open' : 'resolved']);
}
foreach (array_slice($recent, 5, 3) as $r) {
    $db->run('INSERT IGNORE INTO {blocked_users} (blocker_user_id, blocked_user_id, created_at) VALUES (?, ?, ?)', [$r['user_one_id'], $r['user_two_id'], $ts($now - 3600)]);
}
$inboxTexts = ['خیلی آدم باحالی هستی 😍', 'یادته سر کلاس چی شد؟ 😂', 'دلم برات تنگ شده', 'حدس بزن کی‌ام!', 'تولدت مبارک 🎂', 'یه سوال: از من خوشت میاد؟'];
for ($k = 0; $k < 22; $k++) {
    $owner = $ids[mt_rand(1, 30)];
    $visitor = $ids[mt_rand(31, 139)];
    $db->run('INSERT INTO {anonymous_sessions} (owner_user_id, visitor_user_id, created_at, last_message_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id', [$owner, $visitor, $ts($now - 86400), $ts($now)]);
    $sid = (int) $db->value('SELECT id FROM {anonymous_sessions} WHERE owner_user_id = ? AND visitor_user_id = ?', [$owner, $visitor]);
    $first = $db->insert('anonymous_inbox', ['session_id' => $sid, 'recipient_user_id' => $owner, 'sender_user_id' => $visitor, 'message_type' => 'text', 'text_content' => $pick($inboxTexts),
        'created_at' => $ts($now - mt_rand(60, 6 * 86400)), 'status' => $pick(['seen', 'seen', 'new', 'blocked'])]);
    if (mt_rand(0, 1)) {
        $db->insert('anonymous_inbox', ['session_id' => $sid, 'recipient_user_id' => $visitor, 'sender_user_id' => $owner, 'reply_to_id' => $first, 'message_type' => 'text',
            'text_content' => $pick(['مرسی 🙏 تو کی هستی؟', 'هاها! بگو کی هستی', 'ممنونم ❤️']), 'created_at' => $ts($now - mt_rand(30, 3000)), 'status' => 'seen']);
    }
}

// ---------------------------------------------------------------- 7 days of request metrics (busy evenings,
// a traffic spike 25 minutes ago that stayed fast, a slow hour two days ago)
for ($m = 7 * 1440; $m >= 1; $m--) {
    $t = $now - $m * 60;
    $hour = (int) gmdate('G', $t + 12600);
    $req = max(0, (int) round($hourWeight[$hour] * mt_rand(80, 140) / 100 * 2.2));
    if ($m >= 22 && $m <= 27) {
        $req = $req * 4 + 30;
    }
    if (!$req) {
        continue;
    }
    $avg = mt_rand(110, 260) + $hourWeight[$hour] * 9;
    if ($m >= 2 * 1440 && $m < 2 * 1440 + 60) {
        $avg = mt_rand(1100, 1900);
    }
    $db->run('INSERT INTO {request_metrics} (minute_at, source, requests, total_ms, max_ms, slow, errors, bale_calls, bale_ms, bale_errors, db_queries, max_memory_kb)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)', [
        gmdate('Y-m-d H:i:00', $t), 'webhook', $req, $req * $avg, (int) ($avg * mt_rand(150, 320) / 100), mt_rand(0, 100) < 2 ? 1 : 0, mt_rand(0, 1000) < 3 ? 1 : 0,
        (int) round($req * 1.6), (int) round($req * 1.6 * mt_rand(70, 160)), $req * mt_rand(9, 15), mt_rand(2048, 4096),
    ]);
}
$db->run("INSERT INTO {settings} (name, value, updated_at) VALUES ('_tick', ?, ?), ('_purge', ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [(string) ($now - 8), $ts($now), (string) ($now - 1800), $ts($now)]);
$db->insert('error_logs', ['level' => 'warning', 'message' => 'relay failed', 'context' => '{"chat":131,"type":"video","description":"Bad Request: file is too big"}', 'created_at' => $ts($now - 7200)]);

echo 'demo data: ' . $db->value('SELECT COUNT(*) FROM {users}') . ' users, ' . $db->value('SELECT COUNT(*) FROM {chats}') . ' chats, ' . $db->value('SELECT COUNT(*) FROM {messages}') . " messages\n";
