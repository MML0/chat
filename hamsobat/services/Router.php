<?php
// Decides which handler gets an update. Order for a private message:
//   /start (+ deep link) → admin commands → a pending input (profile step, anonymous
//   message, report details) → active chat (relay or chat buttons) → searching → menu.
// A menu button always wins over a pending input, so nobody gets stuck in a step.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Router
{
    /** callback_data prefix → handler */
    private const CALLBACKS = [
        'rg' => ProfileHandler::class, 'pe' => ProfileHandler::class, 'pf' => ProfileHandler::class, 'bd' => ProfileHandler::class,
        'fd' => MatchingHandler::class, 'fg' => MatchingHandler::class, 'sc' => MatchingHandler::class,
        'ch' => ChatHandler::class, 'rr' => ChatHandler::class, 'rd' => ChatHandler::class,
        'rq' => ReferralHandler::class, 'inv' => ReferralHandler::class,
        'ib' => InboxHandler::class, 'ix' => InboxHandler::class, 'ic' => InboxHandler::class,
        'st' => SettingsHandler::class,
        'home' => StartHandler::class,
        'ar' => AdminHandler::class,
    ];

    public function __construct(private App $app)
    {
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);
        } elseif (isset($update['my_chat_member'])) {
            $this->memberUpdate($update['my_chat_member']);
        } elseif (isset($update['message'])) {
            $this->message($update['message']);
        }
        // edited messages, channel posts …: ignored
    }

    private function message(array $m): void
    {
        if (($m['chat']['type'] ?? '') !== 'private' || empty($m['from']['id']) || !empty($m['from']['is_bot'])) {
            return; // groups and channels are not for this bot
        }
        $app = $this->app;
        $baleId = (int) $m['from']['id'];
        [$user, $isNew] = $app->users()->fromBale($baleId, $m['from']);
        $admin = $app->isAdmin($baleId);
        if (!$admin && $this->blocked($user)) {
            return;
        }

        $text = isset($m['text']) ? Text::normalize((string) $m['text'], true) : '';
        $start = $app->handler(StartHandler::class);

        if (preg_match('~^/start(?:@\w+)?(?:\s+(.*))?$~s', $text, $mm)) {
            $start->start($user, trim($mm[1] ?? ''), $isNew);
            return;
        }
        if ($admin && str_starts_with($text, '/') && $app->handler(AdminHandler::class)->command($user, $text)) {
            return;
        }
        if ($text === '/help') {
            $start->guide($user);
            return;
        }
        $label = isset($m['text']) ? Ui::label($text) : null;
        if ($text === '/menu') {
            $label = '/menu';
        }

        [$state, $data] = $app->users()->state($user);
        if ($state !== '') {
            if ($label !== null) {
                $app->users()->clearState((int) $user['id']); // a button press leaves the step
            } elseif ($this->stateInput($user, $state, $data, $m)) {
                return;
            }
        }

        $chat = $app->matching()->activeChat((int) $user['id']);
        if ($chat) {
            $app->handler(ChatHandler::class)->onMessage($user, $chat, $m, $label);
            return;
        }
        if ($label === null && $app->matching()->isSearching((int) $user['id'])) {
            $app->handler(MatchingHandler::class)->whileSearching($user);
            return;
        }

        match ($label) {
            Ui::FIND, Ui::NEXT          => $app->handler(MatchingHandler::class)->find($user),
            Ui::CANCEL_SEARCH           => $app->handler(MatchingHandler::class)->cancel($user),
            Ui::MY_PROFILE              => $app->handler(ProfileHandler::class)->showMine($user),
            Ui::COMPLETE_PROFILE        => $app->handler(ProfileHandler::class)->begin($user),
            Ui::INBOX                   => $app->handler(InboxHandler::class)->menu($user),
            Ui::INVITE                  => $app->handler(ReferralHandler::class)->invite($user),
            Ui::SETTINGS                => $app->handler(SettingsHandler::class)->menu($user),
            Ui::GUIDE                   => $start->guide($user),
            Ui::END, Ui::VIEW_PROFILE, Ui::BLOCK, Ui::REPORT => $start->notChatting($user),
            '/menu'                     => $start->home($user),
            default                     => $start->fallback($user),
        };
    }

    /** A pending input step consumes the message. Returns false when the state was unknown. */
    private function stateInput(array $user, string $state, array $data, array $m): bool
    {
        $owner = strstr($state, ':', true) ?: $state;
        $handler = match ($owner) {
            'reg', 'edit' => ProfileHandler::class,
            'inbox'       => InboxHandler::class,
            'report'      => ChatHandler::class,
            default       => null,
        };
        if ($handler === null) {
            $this->app->users()->clearState((int) $user['id']);
            return false;
        }
        return $this->app->handler($handler)->onInput($user, $state, $data, $m);
    }

    private function callback(array $cb): void
    {
        $baleId = (int) ($cb['from']['id'] ?? 0);
        $id = (string) ($cb['id'] ?? '');
        if ($baleId === 0 || $id === '') {
            return;
        }
        $result = null;
        try {
            [$user] = $this->app->users()->fromBale($baleId, (array) ($cb['from'] ?? []));
            $admin = $this->app->isAdmin($baleId);
            $data = (string) ($cb['data'] ?? '');
            $prefix = strstr($data, ':', true) ?: $data;
            $handler = self::CALLBACKS[$prefix] ?? null;
            if (!$admin && $this->blocked($user, true)) {
                $result = ['text' => '⛔ دسترسی تو فعلاً بسته است.', 'alert' => true];
            } elseif ($handler === AdminHandler::class && !$admin) {
                $result = null;
            } elseif ($handler !== null && strlen($data) <= 64) {
                $result = $this->app->handler($handler)->onCallback($user, $data, $cb);
            }
        } finally {
            // Always answered (even after an error), or the button keeps spinning.
            $toast = is_array($result) ? (string) ($result['text'] ?? '') : (string) ($result ?? '');
            $this->app->bale->answer($id, $toast, is_array($result) && !empty($result['alert']));
        }
    }

    /** The user blocked the bot (or unblocked it). End their chat and search. */
    private function memberUpdate(array $u): void
    {
        if (($u['chat']['type'] ?? '') !== 'private' || ($u['new_chat_member']['status'] ?? '') !== 'kicked') {
            return;
        }
        $user = $this->app->db->row('SELECT * FROM {users} WHERE bale_user_id = ?', [(int) ($u['from']['id'] ?? 0)]);
        if (!$user) {
            return;
        }
        $this->app->matching()->cancel((int) $user['id']);
        $ended = $this->app->matching()->end((int) $user['id'], null, 'left');
        if ($ended) {
            $this->app->handler(ChatHandler::class)->tellPartnerEnded($ended['partner_id'], $ended['chat_id'], '🏁 هم‌صحبتت از ربات خارج شد و چت تموم شد.');
        }
    }

    /** Maintenance mode or suspension. Tells the user at most once per hour. */
    private function blocked(array $user, bool $silent = false): bool
    {
        $maintenance = $this->app->settings->bool('maintenance');
        if (!$maintenance && !$this->app->users()->isSuspended($user)) {
            return false;
        }
        if (!$silent && $this->app->rate()->hit((int) $user['id'], 'blocked_note', 3600) === 1) {
            $this->app->bale->send((int) $user['bale_user_id'], $maintenance
                ? '🛠 هم‌صحبت در حال به‌روزرسانیه. کمی بعد دوباره سر بزن 🙏'
                : '⛔ حساب تو به‌خاطر گزارش کاربران فعلاً مسدود است.' . ($user['suspended_until'] ? "\nتا: " . Text::fa(substr($user['suspended_until'], 0, 16)) . ' (UTC)' : ''));
        }
        return true;
    }
}
