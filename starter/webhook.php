<?php
// ============================================================================
// webhook.php — Bale sends every update here (set it with check.php?setwebhook=1).
//
// Order matters:
//   1. secret check  2. decode  3. DB + dedupe
//   4. pre_checkout_query FIRST (Bale waits ≤ 10 s for the answer)
//   5. callback buttons  6. successful_payment  7. messages / commands  8. app logic
// The app logic (random chat) goes where it says "APP LOGIC".
// ============================================================================
$config = require __DIR__ . '/config.php';
require __DIR__ . '/src/bootstrap.php';

use App\Bale;
use App\Db;
use App\Payments;
use App\Premium;
use App\UpdateDeduper;

// 1) Only Bale knows the secret in the webhook URL.
$secret = (string) ($config['bale']['webhook_secret'] ?? '');
if ($secret !== '' && !hash_equals($secret, (string) ($_GET['k'] ?? ''))) {
    http_response_code(403);
    exit;
}

// 2) Decode
$raw = (string) file_get_contents('php://input');
$update = json_decode($raw, true);
if (!is_array($update)) {
    exit;
}

$bale  = new Bale($config['bale']['bot_token'], $config['bale']['api_base'] ?? 'https://tapi.bale.ai');
$admin = (int) $config['admin']['chat_id'];
$notifyAdmin = function (string $text) use ($bale, $admin) {
    $bale->sendMessage($admin, mb_substr($text, 0, 3500));
};

// 3) DB + dedupe
try {
    $db = Db::connect($config);
} catch (Throwable $e) {
    error_log('[webhook] DB connection failed: ' . $e->getMessage());
    http_response_code(500); // Bale will retry later
    exit;
}
$p = Db::prefix($config);
if (!(new UpdateDeduper($db, "{$p}updates_seen"))->firstSeen($update, $raw)) {
    exit;
}

$premium  = new Premium($db, "{$p}premium");
$payments = new Payments(
    $db,
    $bale,
    $config['payment'],
    "{$p}payments",
    function (int $userId, string $key, array $pkg, string $chargeId) use ($premium) {
        $premium->grant($userId, (int) $pkg['days'], Premium::PAYMENT, $chargeId);
        // APP: anything else a purchase unlocks
    },
    $notifyAdmin,
    null,
    fn (string $chargeId) => $premium->hasReference($chargeId)
);

// 4) Accepting the payment — first and fast.
if (isset($update['pre_checkout_query'])) {
    $payments->handlePreCheckout($update['pre_checkout_query']);
    exit;
}

// 5) Inline buttons. callback_data is short: "action:arg" (Bale/Telegram limit 64 bytes).
if (isset($update['callback_query'])) {
    $cb     = $update['callback_query'];
    $from   = (int) $cb['from']['id'];
    $chatId = $cb['message']['chat']['id'] ?? $from;
    [$action, $arg] = array_pad(explode(':', (string) ($cb['data'] ?? ''), 2), 2, '');

    switch ($action) {
        case 'buy':
            $bale->answerCallback($cb['id'], '🧾 در حال ساخت فاکتور…');
            if ($payments->sendInvoice($chatId, $from, $arg)) {
                $bale->sendMessage($chatId, '🧾 فاکتور پرداخت ارسال شد. لطفاً پرداخت را تکمیل کنید.');
            }
            break;
        // APP: more buttons here
        default:
            $bale->answerCallback($cb['id']); // always answer, or the button keeps spinning
    }
    exit;
}

$message = $update['message'] ?? null;
if (!$message) {
    exit; // edited messages, channel posts…: ignored
}

// 6) Payment finished in the wallet
if (isset($message['successful_payment'])) {
    $payments->handleSuccessfulPayment($message);
    exit;
}

// 7) Users and commands (private chats only)
if (($message['chat']['type'] ?? '') !== 'private') {
    exit;
}
$from   = $message['from'];
$userId = (int) $from['id'];
$chatId = $message['chat']['id'];
$text   = trim((string) ($message['text'] ?? ''));
$now    = date('Y-m-d H:i:s');

$stmt = $db->prepare("SELECT * FROM {$p}users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$isNew = !$user;
if ($isNew) {
    $referrer = preg_match('~^/start\s+ref(\d+)$~', $text, $m) && (int) $m[1] !== $userId ? (int) $m[1] : null;
    $db->prepare("INSERT INTO {$p}users (user_id, username, first_name, last_name, referred_by, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$userId, $from['username'] ?? null, $from['first_name'] ?? null, $from['last_name'] ?? null, $referrer, $now, $now]);
    if ($referrer) {
        // Free reward for the inviter (referral time never unlocks paid-only features).
        $premium->grant($referrer, 1, Premium::REFERRAL, "ref:{$userId}");
        $bale->sendMessage($referrer, '🎉 یک نفر با لینک شما عضو شد و ۱ روز اشتراک ویژه هدیه گرفتید!');
    }
} else {
    $db->prepare("UPDATE {$p}users SET username = ?, first_name = ?, last_name = ?, last_seen_at = ? WHERE user_id = ?")
        ->execute([$from['username'] ?? null, $from['first_name'] ?? null, $from['last_name'] ?? null, $now, $userId]);
    if (!empty($user['banned'])) {
        exit;
    }
}

$isAdmin = $userId === $admin;
$lower = mb_strtolower(preg_replace('~@' . preg_quote((string) $config['bale']['bot_username'], '~') . '\b~i', '', $text));

if (str_starts_with($lower, '/start')) {
    $bale->sendMessage($chatId, "👋 خوش آمدید!\n\nبرای راهنما /help را بزنید.");
    exit;
}
if ($lower === '/help') {
    $left = $premium->remainingText($userId);
    $bale->sendMessage($chatId, "📌 دستورات:\n\n/start – شروع\n/help – راهنما\n/buy – خرید اشتراک ویژه 💳\n/invite – لینک دعوت 🎁"
        . ($left ? "\n\n💎 اشتراک ویژه: {$left} باقی مانده" : ''));
    exit;
}
if ($lower === '/buy') {
    $rows = [];
    foreach ($payments->packages() as $key => $pkg) {
        $rows[] = [['text' => $pkg['title'] . ' | ' . number_format($pkg['amount'] / 10) . ' تومان', 'callback_data' => "buy:{$key}"]];
    }
    $bale->sendInlineButtons($chatId, '🛒 بسته مورد نظر را انتخاب کنید:', $rows);
    exit;
}
if ($lower === '/invite') {
    $link = "https://ble.ir/{$config['bale']['bot_username']}?start=ref{$userId}";
    $bale->sendMessage($chatId, "🎁 لینک دعوت شما:\n{$link}\n\nهر دوستی که با این لینک وارد شود، ۱ روز اشتراک ویژه برای شما.");
    exit;
}

// Admin: /grant <user_id> <days> [receipt], /revoke <user_id>
if ($isAdmin && preg_match('~^/grant\s+(\d+)\s+(\d{1,4})(?:\s+(\S{1,100}))?$~', $text, $m)) {
    $until = $premium->grant((int) $m[1], (int) $m[2], Premium::ADMIN, $m[3] ?? null, $admin);
    $bale->sendMessage($chatId, "✅ اشتراک {$m[1]} تا {$until}");
    $bale->sendMessage((int) $m[1], '🎉 اشتراک ویژه شما فعال شد!');
    exit;
}
if ($isAdmin && preg_match('~^/revoke\s+(\d+)$~', $text, $m)) {
    $bale->sendMessage($chatId, '✅ لغو شد: ' . $premium->revoke((int) $m[1]));
    exit;
}

// 8) APP LOGIC — random chat goes here (see docs/06-random-chat-design.md).
$bale->sendMessage($chatId, 'برای راهنما /help را بزنید.');
