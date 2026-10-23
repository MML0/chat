<?php
// Anonymous inbox: https://ble.ir/<bot>?start=msg<code> lets anyone send the link owner
// an anonymous message. Replies stay in their own anonymous session and can never enter
// a random chat. States: inbox:compose {to}, inbox:reply {msg}.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class InboxHandler extends Handler
{
    private const TTL = 900; // a pending "write your message" expires after 15 minutes

    public function link(array $user): string
    {
        return $this->app->deepLink('msg' . $user['inbox_code']);
    }

    /** Someone opened an anonymous-message link. */
    public function openLink(array $visitor, string $code): void
    {
        if (!$this->app->settings->bool('feature_inbox')) {
            $this->say($visitor, '❌ این امکان فعلاً غیرفعاله.', $this->keyboardFor($visitor));
            return;
        }
        $owner = $this->app->db->row('SELECT * FROM {users} WHERE inbox_code = ?', [$code]);
        if (!$owner || $owner['status'] !== 'active') {
            $this->say($visitor, '❌ این لینک معتبر نیست یا صاحبش اون رو عوض کرده.', $this->keyboardFor($visitor));
            return;
        }
        if ((int) $owner['id'] === (int) $visitor['id']) {
            $this->say($visitor, "😄 این لینک خودته! بفرستش برای دوستات تا ناشناس برات پیام بدن:\n" . $this->link($owner), $this->keyboardFor($visitor));
            return;
        }
        $this->app->users()->setState((int) $visitor['id'], 'inbox:compose', ['to' => (int) $owner['id']], self::TTL);
        $name = $owner['nickname'] ?: 'صاحب این لینک';
        $warn = $this->app->matching()->activeChat((int) $visitor['id']) ? "\n\n⚠️ پیام بعدیت برای «{$name}» می‌ره، نه برای هم‌صحبت فعلیت." : '';
        $this->say($visitor, "✉️ داری برای «{$name}» پیام ناشناس می‌فرستی.\nمتن، عکس یا ویس بفرست؛ اون نمی‌فهمه تو کی هستی 🤫{$warn}", Ui::inline([[['❌ لغو', 'ic:x']]]));
    }

    /** The message after "write your anonymous message" / "write your reply". */
    public function onInput(array $user, string $state, array $data, array $m): bool
    {
        if ($state === 'inbox:compose' && !empty($data['to'])) {
            $this->send($user, (int) $data['to'], null, $m);
            return true;
        }
        if ($state === 'inbox:reply' && !empty($data['msg'])) {
            $orig = $this->app->db->row("SELECT * FROM {anonymous_inbox} WHERE id = ? AND recipient_user_id = ? AND status <> 'blocked'", [(int) $data['msg'], $user['id']]);
            if ($orig) {
                $this->send($user, (int) $orig['sender_user_id'], $orig, $m);
                return true;
            }
        }
        $this->app->users()->clearState((int) $user['id']);
        return false;
    }

    private function send(array $sender, int $toId, ?array $replyTo, array $m): void
    {
        $app = $this->app;
        $c = MessageRelay::extract($m);
        if ($error = $app->relay()->check($c, true)) {
            $this->say($sender, $error); // the state stays: they can try again
            return;
        }
        $to = $app->users()->get($toId);
        if (!$to || !$app->settings->bool('feature_inbox')) {
            $app->users()->clearState((int) $sender['id']);
            $this->say($sender, '❌ ارسال نشد؛ این لینک دیگه فعال نیست.', $this->keyboardFor($sender));
            return;
        }
        if (!$app->rate()->allow((int) $sender['id'], 'inbox', $app->settings->int('inbox_rate_per_hour'), 3600)) {
            $app->users()->clearState((int) $sender['id']);
            $this->say($sender, '⏳ توی این یک ساعت پیام ناشناس زیادی فرستادی. کمی بعد دوباره امتحان کن.', $this->keyboardFor($sender));
            return;
        }
        // A blocked sender is not told: the message is kept as 'blocked' and never delivered.
        $blocked = $app->moderation()->hasBlocked($toId, (int) $sender['id']);
        $now = $app->ts();
        $sessionId = $replyTo ? (int) $replyTo['session_id'] : $this->session($toId, (int) $sender['id']);
        $msgId = $app->db->insert('anonymous_inbox', [
            'session_id'        => $sessionId,
            'recipient_user_id' => $toId,
            'sender_user_id'    => $sender['id'],
            'reply_to_id'       => $replyTo['id'] ?? null,
            'message_type'      => $c['type'],
            'text_content'      => $c['text'] !== '' ? mb_substr($c['text'], 0, 4000) : null,
            'bale_file_id'      => $c['file_id'] ?? null,
            'created_at'        => $now,
            'status'            => $blocked ? 'blocked' : 'new',
        ]);
        $app->db->run('UPDATE {anonymous_sessions} SET last_message_at = ? WHERE id = ?', [$now, $sessionId]);
        $app->users()->clearState((int) $sender['id']);
        if (!$blocked) {
            $this->deliver($to, $app->db->row('SELECT * FROM {anonymous_inbox} WHERE id = ?', [$msgId]));
        }
        $this->say($sender, $replyTo ? '✅ پاسخت ناشناس ارسال شد!' : '✅ پیامت ناشناس ارسال شد!', $this->keyboardFor($sender));
        if (!$replyTo && $sender['profile_completed']) {
            $this->say($sender, '🔗 تو هم می‌تونی لینک ناشناس خودت رو داشته باشی: «' . Ui::INBOX . '»');
        }
    }

    private function session(int $ownerId, int $visitorId): int
    {
        $db = $this->app->db;
        $db->run('INSERT INTO {anonymous_sessions} (owner_user_id, visitor_user_id, created_at, last_message_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id', [$ownerId, $visitorId, $this->app->ts(), $this->app->ts()]);
        return (int) $db->value('SELECT id FROM {anonymous_sessions} WHERE owner_user_id = ? AND visitor_user_id = ?', [$ownerId, $visitorId]);
    }

    /**
     * Shows one inbox message to its recipient. During a random chat only a short notice is
     * sent (the content waits in the inbox), so it can never be mistaken for the partner.
     */
    private function deliver(array $to, array $msg, bool $fromInbox = false): bool
    {
        $app = $this->app;
        if (!$fromInbox && $app->matching()->activeChat((int) $to['id'])) {
            $this->say($to, '📬 یک پیام ناشناس جدید داری! بعد از چت از «' . Ui::INBOX . '» ببینش.');
            return false;
        }
        $session = $app->db->row('SELECT * FROM {anonymous_sessions} WHERE id = ?', [$msg['session_id']]);
        $fromOwner = $session && (int) $session['owner_user_id'] === (int) $msg['sender_user_id'];
        if ($msg['reply_to_id']) {
            $owner = $fromOwner ? $app->users()->get((int) $session['owner_user_id']) : null;
            $header = $owner ? "↩️ «{$owner['nickname']}» به پیام ناشناست جواب داد:\n\n" : "↩️ پاسخ ناشناس به پیامت:\n\n";
        } else {
            $header = "📩 یک پیام ناشناس جدید:\n\n";
        }
        $c = ['type' => $msg['message_type'], 'text' => (string) $msg['text_content'], 'file_id' => $msg['bale_file_id']];
        $res = $app->relay()->deliver((int) $to['bale_user_id'], $c, $header, Ui::inline([
            [['↩️ پاسخ', "ix:r:{$msg['id']}"], ['🚫 مسدود', "ix:b:{$msg['id']}"], ['🚩 گزارش', "ix:p:{$msg['id']}"]],
        ]));
        if (!empty($res['ok'])) {
            $app->db->run("UPDATE {anonymous_inbox} SET status = 'seen' WHERE id = ? AND status = 'new'", [$msg['id']]);
            return true;
        }
        return false;
    }

    /** 📥 پیام‌های ناشناس */
    public function menu(array $user, ?array $cb = null): void
    {
        if (!$this->app->settings->bool('feature_inbox')) {
            $this->say($user, '❌ صندوق پیام ناشناس فعلاً غیرفعاله.', $this->keyboardFor($user));
            return;
        }
        $db = $this->app->db;
        $new = (int) $db->value("SELECT COUNT(*) FROM {anonymous_inbox} WHERE recipient_user_id = ? AND status = 'new'", [$user['id']]);
        $all = (int) $db->value("SELECT COUNT(*) FROM {anonymous_inbox} WHERE recipient_user_id = ? AND status <> 'blocked'", [$user['id']]);
        $text = "📥 پیام‌های ناشناس\n\n🔗 لینک ناشناس تو:\n" . $this->link($user)
            . "\n\nاین لینک رو هر جا بذاری، بقیه می‌تونن بدون اینکه اسمشون معلوم بشه برات پیام بفرستن ✉️"
            . "\n\n📬 پیام‌های نخونده: " . Text::fa($new) . ' | همه: ' . Text::fa($all);
        $markup = Ui::inline([
            [['📬 پیام‌های جدید (' . Text::fa($new) . ')', 'ib:new'], ['🗂 ۵ پیام آخر', 'ib:rec']],
            [['🔄 ساخت لینک جدید', 'ib:reg']],
        ]);
        $cb ? $this->editOrSend($user, $cb, $text, $markup) : $this->say($user, $text, $markup);
    }

    public function onCallback(array $user, string $data, array $cb): string|array|null
    {
        $app = $this->app;
        $db = $app->db;
        $me = (int) $user['id'];

        switch ($data) {
            case 'ic:x':
                $app->users()->clearState($me);
                $this->home($user, 'باشه، لغو شد 👌');
                return null;
            case 'ib:new':
                $rows = $db->all("SELECT * FROM {anonymous_inbox} WHERE recipient_user_id = ? AND status = 'new' ORDER BY id LIMIT 10", [$me]);
                if (!$rows) {
                    return 'پیام جدیدی نداری 📭';
                }
                foreach ($rows as $row) {
                    $this->deliver($user, $row, true);
                }
                return null;
            case 'ib:rec':
                $rows = $db->all("SELECT * FROM {anonymous_inbox} WHERE recipient_user_id = ? AND status = 'seen' ORDER BY id DESC LIMIT 5", [$me]);
                if (!$rows) {
                    return 'هنوز پیامی نداری 📭';
                }
                foreach (array_reverse($rows) as $row) {
                    $this->deliver($user, $row, true);
                }
                return null;
            case 'ib:reg':
                $this->say($user, "🔄 با ساختن لینک جدید، لینک قبلی دیگه کار نمی‌کنه. مطمئنی؟", Ui::inline([[['✅ بله، لینک جدید', 'ib:regy'], ['❌ نه', 'ch:no']]]));
                return null;
            case 'ib:regy':
                for ($i = 0; $i < 5; $i++) {
                    try {
                        $app->users()->update($me, ['inbox_code' => Text::code(10)]);
                        break;
                    } catch (\PDOException $e) {
                        if (!Database::isDuplicate($e)) {
                            throw $e;
                        }
                    }
                }
                $this->menu($this->fresh($user), $cb);
                return '✅ لینک جدید ساخته شد';
        }

        if (!preg_match('~^ix:(r|b|p):(\d{1,10})$~', $data, $m)) {
            return null;
        }
        $msg = $db->row('SELECT * FROM {anonymous_inbox} WHERE id = ? AND recipient_user_id = ?', [(int) $m[2], $me]);
        if (!$msg) {
            return 'این پیام پیدا نشد.';
        }
        switch ($m[1]) {
            case 'r':
                if ($app->matching()->activeChat($me)) {
                    return ['text' => '💬 اول چت فعلی رو تموم کن، بعد جواب بده.', 'alert' => true];
                }
                $app->users()->setState($me, 'inbox:reply', ['msg' => (int) $msg['id']], self::TTL);
                $this->say($user, '✍️ پاسخت رو بنویس یا عکس/ویس بفرست (ناشناس می‌مونی):', Ui::inline([[['❌ لغو', 'ic:x']]]));
                return null;
            case 'b':
                $app->moderation()->block($me, (int) $msg['sender_user_id']);
                $this->say($user, "🚫 فرستنده مسدود شد؛ دیگه نه پیامی ازش می‌گیری و نه به هم وصل می‌شید.\n(از ⚙️ تنظیمات می‌تونی رفعش کنی)");
                return null;
            case 'p':
                return $app->handler(ChatHandler::class)->askReason($user, 'i', (int) $msg['id']);
        }
        return null;
    }
}
