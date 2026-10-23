<?php

namespace App;

use PDO;

/**
 * Bale wallet payments, the flow that works in GPT_BOTble:
 *
 *   sendInvoice  →  user pays  →  pre_checkout_query  →  answerPreCheckoutQuery(ok)  (≤ 10 s!)
 *                →  message.successful_payment  →  inquireTransaction(transaction_id)  →  status "paid"
 *                →  credit the package (once per transaction)
 *
 * Every request is sent like the old working code (form fields, ok=1); see Bale::call().
 */
final class Payments
{
    /** @var callable fn(int $userId, string $packageKey, array $package, string $chargeId): void */
    private $onPaid;
    /** @var callable fn(string $text): void — admin notices */
    private $notifyAdmin;
    /** @var callable fn(float $seconds): void */
    private $sleep;
    /** @var callable|null fn(string $chargeId): bool — e.g. already credited by hand with /grant */
    private $creditedElsewhere;

    /**
     * @param array $cfg ['provider_token' => 'WALLET-…', 'packages' => [key => [title, description, amount (rial), days]], 'debug' => bool]
     */
    public function __construct(
        private PDO $db,
        private Bale $bale,
        private array $cfg,
        private string $table,
        callable $onPaid,
        callable $notifyAdmin,
        ?callable $sleep = null,
        ?callable $creditedElsewhere = null
    ) {
        $this->onPaid = $onPaid;
        $this->notifyAdmin = $notifyAdmin;
        $this->sleep = $sleep ?? fn (float $s) => usleep((int) ($s * 1_000_000));
        $this->creditedElsewhere = $creditedElsewhere;
    }

    public function packages(): array
    {
        return $this->cfg['packages'];
    }

    /** Sends the invoice for one package. Returns true if Bale accepted it. */
    public function sendInvoice($chatId, int $userId, string $packageKey): bool
    {
        $pkg = $this->cfg['packages'][$packageKey] ?? null;
        if (!$pkg) {
            $this->bale->sendMessage($chatId, '❌ بسته انتخابی نامعتبر است.');
            return false;
        }
        $token = (string) ($this->cfg['provider_token'] ?? '');
        if ($token === '' || str_contains($token, 'CHANGE')) {
            $this->bale->sendMessage($chatId, '❌ پرداخت فعلاً در دسترس نیست. لطفاً با پشتیبانی تماس بگیرید.');
            ($this->notifyAdmin)("⚠️ پرداخت غیرفعال است: 'payment' => ['provider_token' => 'WALLET-...'] را در config.php بگذارید.");
            return false;
        }
        $res = $this->bale->call('sendInvoice', [
            'chat_id'        => $chatId,
            'title'          => $pkg['title'],
            'description'    => $pkg['description'],
            'payload'        => self::payload($packageKey, $userId),
            'provider_token' => $token,
            'prices'         => [['label' => $pkg['title'], 'amount' => (int) $pkg['amount']]],
        ], 10);
        if (empty($res['ok'])) {
            $this->bale->sendMessage($chatId, '❌ ارسال فاکتور ناموفق بود. لطفاً کمی بعد دوباره تلاش کنید.');
            ($this->notifyAdmin)('⚠️ sendInvoice ناموفق بود: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
            return false;
        }
        return true;
    }

    /** Must run first and fast: Bale waits at most ~10 seconds. Accepts every payment (the real check comes after). */
    public function handlePreCheckout(array $query): void
    {
        $res = $this->bale->call('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => $query['id'],
            'ok'                    => true,
        ], 6);
        if (empty($res['ok'])) {
            ($this->notifyAdmin)('⚠️ answerPreCheckoutQuery ناموفق بود: ' . json_encode($res, JSON_UNESCAPED_UNICODE));
        } elseif (!empty($this->cfg['debug'])) {
            ($this->notifyAdmin)('✅ pre-checkout تایید شد: ' . ($query['from']['id'] ?? '?') . ' | ' . ($query['invoice_payload'] ?? ''));
        }
    }

    /**
     * Handles message.successful_payment. Returns: credited | duplicate | not_ours | unconfirmed | failed.
     */
    public function handleSuccessfulPayment(array $message): string
    {
        $p        = $message['successful_payment'];
        $userId   = (int) ($message['from']['id'] ?? 0);
        $chatId   = $message['chat']['id'] ?? $userId;
        $chargeId = (string) ($p['telegram_payment_charge_id'] ?? '');
        $parsed   = self::parsePayload((string) ($p['invoice_payload'] ?? ''));

        if (!$parsed || $parsed['user_id'] !== $userId || !isset($this->cfg['packages'][$parsed['package']]) || $chargeId === '') {
            ($this->notifyAdmin)("⚠️ پرداخت ناشناخته از {$userId}: " . json_encode($p, JSON_UNESCAPED_UNICODE));
            return 'not_ours';
        }
        if ($this->alreadyPaid($chargeId)) {
            return 'duplicate';
        }
        $key = $parsed['package'];
        $pkg = $this->cfg['packages'][$key];

        // The transaction can still be settling when this message arrives: ask a few times.
        $status = null;
        $raw = [];
        for ($try = 1; $try <= 4; $try++) {
            $raw = $this->bale->call('inquireTransaction', ['transaction_id' => $chargeId], 8);
            $status = $raw['result']['status'] ?? null;
            if (self::isPaid($status) || self::isFailed($status)) {
                break;
            }
            if ($try < 4) {
                ($this->sleep)(2);
            }
        }
        $final = self::isPaid($status) ? 'paid' : (string) ($status ?? 'unknown');
        $this->record($userId, $key, (int) ($p['total_amount'] ?? 0), (string) ($p['currency'] ?? ''), (string) ($p['invoice_payload'] ?? ''), $chargeId, (string) ($p['provider_payment_charge_id'] ?? ''), $final, (int) $pkg['days']);

        if ($final === 'paid') {
            ($this->onPaid)($userId, $key, $pkg, $chargeId);
            $amount = number_format((int) ($p['total_amount'] ?? 0));
            $this->bale->sendMessage($chatId, "🎉 پرداخت با موفقیت تایید شد!\nبسته: {$pkg['title']}\nمدت: {$pkg['days']} روز\nمبلغ: {$amount} ریال\nشماره تراکنش: {$chargeId}");
            ($this->notifyAdmin)("💰 پرداخت تایید شد: {$userId} | {$key} | {$amount} ریال | {$chargeId}");
            return 'credited';
        }

        $this->bale->sendMessage($chatId, "❌ پرداخت تایید نشد یا هنوز کامل نشده است. اگر مبلغ کسر شده، با پشتیبانی تماس بگیرید.\nوضعیت: " . ($status ?? 'نامشخص'));
        if (self::isFailed($status)) {
            return 'failed';
        }
        ($this->notifyAdmin)("⚠️ پرداخت تایید نشد (وضعیت: " . ($status ?? 'نامشخص') . ")\nکاربر: {$userId} | بسته: {$key} | رسید: {$chargeId}\n"
            . "پاسخ بله: " . json_encode($raw, JSON_UNESCAPED_UNICODE) . "\n"
            . "اگر مبلغ به کیف پول رسیده: /grant {$userId} {$pkg['days']} {$chargeId}");
        return 'unconfirmed';
    }

    public static function payload(string $packageKey, int $userId): string
    {
        return "buy_{$packageKey}_{$userId}_" . time();
    }

    /** @return array{package:string,user_id:int}|null */
    public static function parsePayload(string $payload): ?array
    {
        if (!preg_match('/^buy_([a-z0-9]+)_([0-9]+)_[0-9]+$/', $payload, $m)) {
            return null;
        }
        return ['package' => $m[1], 'user_id' => (int) $m[2]];
    }

    public static function isPaid($status): bool
    {
        return is_string($status) && in_array(strtolower($status), ['paid', 'succeeded', 'success', 'successful', 'completed'], true);
    }

    public static function isFailed($status): bool
    {
        return is_string($status) && in_array(strtolower($status), ['failed', 'failure', 'canceled', 'cancelled', 'rejected', 'refunded', 'expired'], true);
    }

    private function alreadyPaid(string $chargeId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$this->table} WHERE charge_id = ? AND status = 'paid' LIMIT 1");
        $stmt->execute([$chargeId]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
        return $this->creditedElsewhere ? (bool) ($this->creditedElsewhere)($chargeId) : false;
    }

    private function record(int $userId, string $package, int $amount, string $currency, string $payload, string $chargeId, string $providerChargeId, string $status, int $days): void
    {
        try {
            $this->db->prepare("INSERT INTO {$this->table} (user_id, package, amount, currency, payload, charge_id, provider_charge_id, status, days, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$userId, $package, $amount, $currency, $payload, $chargeId, $providerChargeId, $status, $days, date('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if (!Db::isDuplicate($e)) {
                throw $e;
            }
            $this->db->prepare("UPDATE {$this->table} SET status = ? WHERE charge_id = ?")->execute([$status, $chargeId]);
        }
    }
}
