<?php
// Anonymous relay. Messages are re-sent by the bot (sendMessage / sendPhoto … with the
// Bale file_id), so the receiver never sees the sender's name or id, and we fully control
// what is sent. copyMessage (also anonymous) is only a fallback. forwardMessage is never
// used between users because it shows who sent the message.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class MessageRelay
{
    private const MEDIA_ORDER = ['photo', 'voice', 'audio', 'video', 'animation', 'video_note', 'sticker', 'document'];
    public const INBOX_TYPES = ['text', 'photo', 'voice', 'audio'];

    public function __construct(private App $app)
    {
    }

    /**
     * Only the file_id is kept: media is never downloaded, the bot sends Bale's own file
     * again, so size and length do not matter here (Bale enforces its own limits).
     * @return array{type:string,text:string,file_id?:string,kind?:string}
     */
    public static function extract(array $m): array
    {
        if (isset($m['text'])) {
            return ['type' => 'text', 'text' => (string) $m['text']];
        }
        foreach (self::MEDIA_ORDER as $type) {
            if (!empty($m[$type])) {
                $obj = $type === 'photo' ? self::bestPhoto($m['photo']) : $m[$type];
                return [
                    'type'    => $type,
                    'text'    => (string) ($m['caption'] ?? ''),
                    'file_id' => (string) ($obj['file_id'] ?? ''),
                ];
            }
        }
        $kind = 'unknown';
        foreach (['contact', 'location', 'venue', 'poll', 'dice', 'game'] as $k) {
            if (isset($m[$k])) {
                $kind = $k;
            }
        }
        return ['type' => 'unsupported', 'text' => '', 'kind' => $kind];
    }

    /** The largest of the sizes Bale sends for one photo (by pixels, then bytes). */
    public static function bestPhoto(array $sizes): array
    {
        $best = [];
        $score = [-1, -1];
        foreach ($sizes as $p) {
            $s = [(int) ($p['width'] ?? 0) * (int) ($p['height'] ?? 0), (int) ($p['file_size'] ?? 0)];
            if ($s > $score) {
                [$best, $score] = [$p, $s];
            }
        }
        return $best;
    }

    /** Persian reason why this content may not be sent, or null when it is fine. */
    public function check(array $c, bool $inbox = false): ?string
    {
        $s = $this->app->settings;
        if ($c['type'] === 'unsupported') {
            return in_array($c['kind'], ['contact', 'location', 'venue'], true)
                ? '⚠️ ارسال شماره یا موقعیت مکانی مجاز نیست تا ناشناس بمونی 🙂'
                : '⚠️ این نوع پیام پشتیبانی نمی‌شه. متن، عکس یا ویس بفرست.';
        }
        if ($c['type'] !== 'text') {
            if (!$s->bool('feature_media')) {
                return '⚠️ ارسال فایل فعلاً غیرفعاله؛ فقط متن بفرست.';
            }
            $allowed = $inbox ? self::INBOX_TYPES : array_map('trim', explode(',', $s->str('allowed_types')));
            if (!in_array($c['type'], $allowed, true) || ($c['file_id'] ?? '') === '') {
                return '⚠️ ارسال این نوع پیام مجاز نیست. متن، عکس یا ویس بفرست.';
            }
        } elseif (trim($c['text']) === '') {
            return '⚠️ پیام خالیه.';
        }
        if ($s->bool('block_links') && $c['text'] !== '' && Text::hasContact($c['text'])) {
            return '⚠️ ارسال لینک، آیدی یا شماره تلفن مجاز نیست تا همه ناشناس بمونن 🙂';
        }
        return null;
    }

    /** Sends content to a Bale chat as the bot. $prefix goes before the text/caption. */
    public function deliver(int $toBaleId, array $c, string $prefix = '', ?array $markup = null, ?int $fromBaleId = null, ?int $messageId = null): array
    {
        $text = $prefix . $c['text'];
        $res = $c['type'] === 'text'
            ? $this->app->bale->send($toBaleId, $text, $markup)
            : $this->app->bale->sendMedia($c['type'], $toBaleId, (string) $c['file_id'], $text, $markup);
        if (empty($res['ok']) && !BaleApi::isUnreachable($res) && $prefix === '' && $fromBaleId && $messageId) {
            $copy = $this->app->bale->copyMessage($toBaleId, $fromBaleId, $messageId, $markup);
            if (!empty($copy['ok'])) {
                return $copy;
            }
        }
        return $res;
    }

    /**
     * One random-chat message from $sender to $partner.
     * @return array{status:string,error?:string} sent | filtered | limited | unreachable | failed
     */
    public function relayChat(array $sender, array $partner, int $chatId, array $message): array
    {
        $s = $this->app->settings;
        $limit = $s->int('msg_rate_per_minute');
        if ($limit > 0) {
            $hits = $this->app->rate()->hit((int) $sender['id'], 'msg', 60);
            if ($hits > $limit) {
                return ['status' => 'limited', 'notify' => $hits === $limit + 1];
            }
        }
        $c = self::extract($message);
        if ($error = $this->check($c)) {
            $this->store($chatId, $sender, $c, $message, 'filtered');
            return ['status' => 'filtered', 'error' => $error];
        }
        $res = $this->deliver((int) $partner['bale_user_id'], $c, '', null, (int) $sender['bale_user_id'], (int) ($message['message_id'] ?? 0));
        $ok = !empty($res['ok']);
        $this->store($chatId, $sender, $c, $message, $ok ? 'sent' : 'failed');
        if ($ok) {
            $this->app->db->run('UPDATE {chats} SET message_count = message_count + 1, last_message_at = ? WHERE id = ?', [$this->app->ts(), $chatId]);
            return ['status' => 'sent'];
        }
        if (BaleApi::isUnreachable($res)) {
            return ['status' => 'unreachable'];
        }
        $this->app->log->warning('relay failed', ['chat' => $chatId, 'type' => $c['type'], 'description' => $res['description'] ?? '']);
        return ['status' => 'failed'];
    }

    private function store(int $chatId, array $sender, array $c, array $message, string $status): void
    {
        $this->app->db->insert('messages', [
            'chat_id'           => $chatId,
            'sender_user_id'    => $sender['id'],
            'message_type'      => $c['type'] === 'unsupported' ? ($c['kind'] ?? 'unsupported') : $c['type'],
            'text_content'      => $this->app->settings->bool('store_message_text') && $c['text'] !== '' ? mb_substr($c['text'], 0, 4000) : null,
            'bale_file_id'      => $c['file_id'] ?? null,
            'source_message_id' => $message['message_id'] ?? null,
            'created_at'        => $this->app->ts(),
            'delivery_status'   => $status,
        ]);
    }
}
