<?php
// Minimal Bale Bot API client (curl only). Telegram-compatible methods.
//
// call() sends form fields first (arrays as JSON text, true → "1"), which is what the
// working GPT_BOTble bot always sent, and retries once as JSON only if Bale answers
// ok:false. It never retries after a network error, so nothing is sent twice.
// Text is always sent WITHOUT parse_mode: user text can never break the message.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class BaleApi
{
    /** Send method + file field for each media type we relay. */
    public const MEDIA = [
        'photo'      => ['sendPhoto', 'photo'],
        'voice'      => ['sendVoice', 'voice'],
        'audio'      => ['sendAudio', 'audio'],
        'video'      => ['sendVideo', 'video'],
        'animation'  => ['sendAnimation', 'animation'],
        'document'   => ['sendDocument', 'document'],
        'sticker'    => ['sendSticker', 'sticker'],
        'video_note' => ['sendVideoNote', 'video_note'],
    ];

    /** This request's Bale traffic: calls, total milliseconds, failed answers. */
    public array $stats = ['calls' => 0, 'ms' => 0.0, 'errors' => 0];

    /** Callback queries answered in this request (a callback can be answered only once). */
    private array $answered = [];

    private string $base;
    /** @var callable fn(string $url, string|array $body, array $headers, int $timeout): array{0:int,1:string|false} */
    private $transport;

    public function __construct(private string $token, string $base = 'https://tapi.bale.ai', ?callable $transport = null)
    {
        $this->base = rtrim($base, '/');
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** @return array Bale's answer; ['ok' => false, 'description' => …] on failure. */
    public function call(string $method, array $params = [], int $timeout = 10): array
    {
        $params = array_filter($params, fn ($v) => $v !== null);
        $url = "{$this->base}/bot{$this->token}/{$method}";
        $form = array_map(
            fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? '1' : '0') : (string) $v),
            $params
        );
        $res = $this->post($url, $form, [], $timeout);
        if (is_array($res) && empty($res['ok']) && !self::isUnreachable($res)) {
            $json = $this->post($url, json_encode($params, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json'], $timeout);
            if (is_array($json) && !empty($json['ok'])) {
                return $json;
            }
        }
        return $res ?? ['ok' => false, 'description' => 'network error'];
    }

    public function send(int|string $chatId, string $text, ?array $markup = null): array
    {
        return $this->call('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => mb_substr($text, 0, 4000),
            'reply_markup' => $markup,
        ]);
    }

    /** Re-sends a media message by Bale file_id (no "forwarded from"). */
    public function sendMedia(string $type, int|string $chatId, string $fileId, string $caption = '', ?array $markup = null): array
    {
        if (!isset(self::MEDIA[$type])) {
            return ['ok' => false, 'description' => "unsupported media type {$type}"];
        }
        [$method, $field] = self::MEDIA[$type];
        $params = ['chat_id' => $chatId, $field => $fileId, 'reply_markup' => $markup];
        if ($caption !== '' && !in_array($type, ['sticker', 'video_note'], true)) {
            $params['caption'] = mb_substr($caption, 0, 1000);
        }
        return $this->call($method, $params, 20);
    }

    /** Copy WITHOUT the "forwarded from" header. Never use forwardMessage between users. */
    public function copyMessage(int|string $toChatId, int|string $fromChatId, int $messageId, ?array $markup = null): array
    {
        return $this->call('copyMessage', ['chat_id' => $toChatId, 'from_chat_id' => $fromChatId, 'message_id' => $messageId, 'reply_markup' => $markup]);
    }

    public function editText(int|string $chatId, int $messageId, string $text, ?array $markup = null): array
    {
        return $this->call('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => mb_substr($text, 0, 4000), 'reply_markup' => $markup]);
    }

    public function editMarkup(int|string $chatId, int $messageId, ?array $markup): array
    {
        return $this->call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => $markup ?? ['inline_keyboard' => []]]);
    }

    /** Every callback must be answered, or the button keeps spinning. */
    public function answer(string $callbackId, string $text = '', bool $alert = false): array
    {
        if (isset($this->answered[$callbackId])) {
            return ['ok' => true, 'result' => true];
        }
        $this->answered[$callbackId] = true;
        return $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert], 5);
    }

    /** Membership check. The bot must be an admin of the channel to see its members. */
    public function getChatMember(int|string $chatId, int $userId): array
    {
        return $this->call('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId], 8);
    }

    /** Chat info; for a private chat: first_name, last_name, username of the user. */
    public function getChat(int|string $chatId): array
    {
        return $this->call('getChat', ['chat_id' => $chatId], 8);
    }

    /** True when the user can no longer get messages (blocked the bot, deleted account …). */
    public static function isUnreachable(array $res): bool
    {
        if (!empty($res['ok'])) {
            return false;
        }
        $code = (int) ($res['error_code'] ?? $res['_http'] ?? 0);
        return $code === 403
            || (bool) preg_match('~blocked|forbidden|deactivated|user not found|chat not found|kicked~i', (string) ($res['description'] ?? ''));
    }

    // ------------------------------------------------------------------ transport

    private function post(string $url, $body, array $headers, int $timeout): ?array
    {
        $t = hrtime(true);
        [$code, $raw] = ($this->transport)($url, $body, $headers, $timeout);
        $this->stats['calls']++;
        $this->stats['ms'] += (hrtime(true) - $t) / 1e6;
        if ($raw === false || $raw === '') {
            $this->stats['errors']++;
            return null; // network error: we do not know whether Bale processed it
        }
        $json = json_decode($raw, true);
        if (empty($json['ok'])) {
            $this->stats['errors']++;
        }
        return is_array($json)
            ? $json + ['_http' => $code]
            : ['ok' => false, '_http' => $code, 'description' => mb_substr((string) $raw, 0, 200)];
    }

    public static function curlTransport(string $url, $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $raw];
    }
}
