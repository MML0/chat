<?php

namespace App;

/**
 * Minimal Bale Bot API client (curl only, no Composer).
 *
 * call() first sends form fields (lists/arrays as JSON text, true → "1"), which is what
 * the working GPT_BOTble bot always sent, payments included. Only if Bale explicitly
 * rejects that does it retry once as JSON. It never retries after a network error,
 * so an invoice or message is never sent twice.
 */
final class Bale
{
    private string $token;
    private string $base;
    /** @var callable fn(string $url, string|array $body, array $headers, int $timeout): array{0:int,1:string|false} */
    private $transport;

    public function __construct(string $token, string $base = 'https://tapi.bale.ai', ?callable $transport = null)
    {
        $this->token     = $token;
        $this->base      = rtrim($base, '/');
        $this->transport = $transport ?? [self::class, 'curlTransport'];
    }

    /** @return array Bale's decoded answer; ['ok' => false, 'description' => …] on failure */
    public function call(string $method, array $params = [], int $timeout = 10): array
    {
        $url  = "{$this->base}/bot{$this->token}/{$method}";
        $form = array_map(
            fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? ($v ? '1' : '0') : $v),
            $params
        );
        $res = $this->send($url, $form, [], $timeout);
        if (is_array($res) && empty($res['ok'])) {
            $json = $this->send($url, json_encode($params, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json'], $timeout);
            if (is_array($json) && !empty($json['ok'])) {
                return $json;
            }
        }
        return $res ?? ['ok' => false, 'description' => 'network error'];
    }

    // ------------------------------------------------------------------ helpers

    public function sendMessage($chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text] + $extra);
    }

    /** Inline (glass) buttons under a message. $rows = [[['text' => '…', 'callback_data' => '…'], …], …] */
    public function sendInlineButtons($chatId, string $text, array $rows, array $extra = []): array
    {
        return $this->sendMessage($chatId, $text, ['reply_markup' => ['inline_keyboard' => $rows]] + $extra);
    }

    /** Persistent keyboard at the bottom of the chat. $rows = [['🔍 شروع', '⛔ پایان'], …] */
    public function sendReplyKeyboard($chatId, string $text, array $rows, array $extra = []): array
    {
        $keyboard = array_map(fn ($row) => array_map(fn ($t) => ['text' => $t], $row), $rows);
        return $this->sendMessage($chatId, $text, ['reply_markup' => ['keyboard' => $keyboard, 'resize_keyboard' => true]] + $extra);
    }

    /** Always answer a callback, otherwise the button keeps spinning. */
    public function answerCallback(string $callbackId, string $text = '', bool $alert = false): array
    {
        return $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert]);
    }

    public function chatAction($chatId, string $action = 'typing'): array
    {
        return $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action], 5);
    }

    /** Copies a message WITHOUT the "forwarded from" header (keeps the sender anonymous). */
    public function copyMessage($toChatId, $fromChatId, int $messageId): array
    {
        return $this->call('copyMessage', ['chat_id' => $toChatId, 'from_chat_id' => $fromChatId, 'message_id' => $messageId]);
    }

    /** Forward WITH "forwarded from" (reveals the sender — never use it between anonymous users). */
    public function forwardMessage($toChatId, $fromChatId, int $messageId): array
    {
        return $this->call('forwardMessage', ['chat_id' => $toChatId, 'from_chat_id' => $fromChatId, 'message_id' => $messageId]);
    }

    public function fileUrl(string $filePath): string
    {
        return "{$this->base}/file/bot{$this->token}/" . ltrim($filePath, '/');
    }

    /** Downloads a file by file_id into $dest. Returns bytes written, or null. Refuses files over $maxBytes. */
    public function downloadFile(string $fileId, string $dest, int $maxBytes): ?int
    {
        $info = $this->call('getFile', ['file_id' => $fileId], 15);
        $path = $info['result']['file_path'] ?? null;
        if (!is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }
        if (isset($info['result']['file_size']) && (int) $info['result']['file_size'] > $maxBytes) {
            return null;
        }
        $fh = fopen($dest, 'wb');
        $ch = curl_init($this->fileUrl($path));
        $written = 0;
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($fh, &$written, $maxBytes) {
                $written += strlen($chunk);
                return $written > $maxBytes ? 0 : fwrite($fh, $chunk); // returning 0 aborts
            },
        ]);
        $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $written <= $maxBytes;
        curl_close($ch);
        fclose($fh);
        return $ok ? $written : null;
    }

    // ------------------------------------------------------------------ transport

    private function send(string $url, $body, array $headers, int $timeout): ?array
    {
        [$code, $raw] = ($this->transport)($url, $body, $headers, $timeout);
        if ($raw === false || $raw === '') {
            return null; // network error: we do not know whether Bale processed it
        }
        $json = json_decode($raw, true);
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
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $raw];
    }
}
