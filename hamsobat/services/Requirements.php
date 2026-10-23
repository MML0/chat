<?php
// What a user needs before random chat: a complete profile, membership in the required
// channel (checked on the server with getChatMember, never trusted from a button) and
// enough qualified referrals. Admins skip the channel and referral steps (for testing).
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Requirements
{
    private const MEMBER_CACHE_SECONDS = 600;

    public function __construct(private App $app)
    {
    }

    public function channelRequired(): bool
    {
        return $this->app->settings->bool('require_channel') && $this->app->settings->str('required_channel') !== '';
    }

    /**
     * @return array{profile:bool,channel_required:bool,channel:bool,refs:int,refs_needed:int,ok:bool}
     */
    public function status(array $user, bool $fresh = false): array
    {
        $admin = $this->app->isAdmin((int) $user['bale_user_id']);
        $profile = (bool) $user['profile_completed'] && !Users::missing($user);
        $channelRequired = $this->channelRequired() && !$admin;
        $channel = !$channelRequired || $this->isChannelMember($user, $fresh);
        $needed = ($admin || $user['referral_waived']) ? 0 : max(0, $this->app->settings->int('required_referrals'));
        $refs = $needed > 0 ? $this->app->referrals()->qualifiedCount((int) $user['id']) : 0;
        return [
            'profile'          => $profile,
            'channel_required' => $channelRequired,
            'channel'          => $channel,
            'refs'             => $refs,
            'refs_needed'      => $needed,
            'ok'               => $profile && $channel && $refs >= $needed,
        ];
    }

    /** getChatMember on the required channel. A positive answer is cached for 10 minutes. */
    public function isChannelMember(array $user, bool $fresh = false): bool
    {
        if (!$this->channelRequired()) {
            return true;
        }
        $verified = $user['channel_verified_at'] ? strtotime($user['channel_verified_at'] . ' UTC') : 0;
        if (!$fresh && $verified > $this->app->now() - self::MEMBER_CACHE_SECONDS) {
            return true;
        }
        $channel = $this->channelTarget();
        $res = $this->app->bale->getChatMember($channel, (int) $user['bale_user_id']);
        if (!empty($res['ok'])) {
            $status = (string) ($res['result']['status'] ?? '');
            $member = in_array($status, ['creator', 'owner', 'administrator', 'member'], true)
                || ($status === 'restricted' && !empty($res['result']['is_member']));
            $this->app->users()->update((int) $user['id'], ['channel_verified_at' => $member ? $this->app->ts() : null]);
            return $member;
        }
        // "user not found / not a participant" simply means not a member.
        $desc = (string) ($res['description'] ?? '');
        if (preg_match('~user not found|not a member|participant|USER_NOT_PARTICIPANT~i', $desc)) {
            return false;
        }
        // Bale's "no such group or user" is ambiguous: wrong channel, bot not an admin, or just a
        // user who has not joined. If the channel itself checks out (the bot is an admin there),
        // the user is simply not a member. Otherwise it is a setup problem for the admin.
        $diag = $this->diagnosis();
        if ($diag['code'] === 'ok') {
            return false;
        }
        $this->app->log->warning('getChatMember failed', ['channel' => $channel, 'description' => $desc, 'diagnosis' => $diag['code']]);
        $this->app->alertAdminsOnce('channel_check', $diag['message']);
        return $this->app->settings->bool('channel_check_fail_open');
    }

    /**
     * What getChatMember is asked about: the channel's numeric id when it is known (found
     * with getChat or from a forwarded post), else the @username from the settings.
     */
    public function channelTarget(): string
    {
        $channel = $this->app->settings->str('required_channel');
        $resolved = (string) ($this->app->settings->raw('_channel_id') ?? '');
        if (str_starts_with($channel, '@') && str_starts_with($resolved, strtolower($channel) . '=')) {
            return substr($resolved, strlen($channel) + 1);
        }
        return $channel;
    }

    /** diagnose(), remembered for 10 minutes (it costs 2–3 Bale calls). */
    public function diagnosis(): array
    {
        $cached = json_decode((string) ($this->app->settings->raw('_channel_diag') ?? ''), true);
        if (is_array($cached) && ($cached['channel'] ?? '') === $this->app->settings->str('required_channel') && ($cached['at'] ?? 0) > $this->app->now() - 600) {
            return $cached;
        }
        return $this->diagnose();
    }

    /**
     * Checks the channel setup the way Bale sees it.
     * @return array{code:string,message:string,channel:string,id:?string,title:?string,username:?string,bot_status:?string,at:int}
     *   code: ok | not_admin | not_found | no_channel
     */
    public function diagnose(): array
    {
        $s = $this->app->settings;
        $channel = $s->str('required_channel');
        $out = ['code' => 'no_channel', 'message' => 'کانال اجباری تنظیم نشده.', 'channel' => $channel, 'id' => null, 'title' => null, 'username' => null, 'bot_status' => null, 'at' => $this->app->now()];
        if ($channel === '') {
            return $out;
        }
        // 1) Find the channel (getChat also gives its numeric id for @username channels).
        $chat = $this->app->bale->getChat($channel);
        if (!empty($chat['ok'])) {
            $out['id'] = (string) ($chat['result']['id'] ?? '');
            $out['title'] = $chat['result']['title'] ?? null;
            $out['username'] = $chat['result']['username'] ?? null;
            if ($out['id'] !== '' && str_starts_with($channel, '@')) {
                $s->internal('_channel_id', strtolower($channel) . '=' . $out['id'], $this->app->now());
            }
        }
        // 2) Is the bot an admin there? (Bale only lets admins check members.)
        $botId = $this->botId();
        $me = $botId ? $this->app->bale->getChatMember($out['id'] ?: $channel, $botId) : [];
        $out['bot_status'] = $me['result']['status'] ?? null;
        $name = $out['title'] ? "«{$out['title']}» ({$channel})" : $channel;
        if (!empty($me['ok']) && in_array($out['bot_status'], ['administrator', 'creator', 'owner'], true)) {
            $out['code'] = 'ok';
            $out['message'] = "✅ کانال {$name} درست تنظیم شده و ربات ادمین آن است.";
        } elseif (!empty($me['ok']) && in_array($out['bot_status'], ['left', 'kicked'], true)) {
            $out['code'] = 'not_found';
            $out['message'] = "❌ ربات عضو کانال {$name} نیست.\n\n👈 ربات را به کانال اضافه کنید و «مدیر» کنید؛ بله فقط به مدیرها اجازه بررسی عضویت می‌دهد.";
        } elseif (!empty($me['ok'])) {
            $out['code'] = 'not_admin';
            $out['message'] = "⚠️ ربات در کانال {$name} هست ولی ادمین نیست، و بله فقط به ادمین‌ها اجازه بررسی عضویت می‌دهد.\n\n👈 ربات را در تنظیمات کانال «مدیر» کنید.";
        } else {
            $out['code'] = 'not_found';
            $why = (string) ($me['description'] ?? $chat['description'] ?? '?');
            $out['message'] = "❌ بله کانال {$channel} را برای ربات پیدا نمی‌کند ({$why}).\n\n"
                . "۱) ربات را به کانال اضافه و «مدیر» کنید (بدون مدیر بودن، بله عضویت را نشان نمی‌دهد).\n"
                . "۲) آیدی را چک کنید: باید آیدی عمومی خود کانال باشد؛ کانال خصوصی آیدی @ ندارد.\n"
                . "۳) راحت‌ترین راه: یک پست از همان کانال را برای ربات فوروارد کنید؛ ربات آیدی عددی کانال را پیدا می‌کند و با یک دکمه تنظیمش می‌کند.";
        }
        $s->internal('_channel_diag', (string) json_encode($out, JSON_UNESCAPED_UNICODE), $this->app->now());
        return $out;
    }

    /** The bot's own user id (getMe), remembered. */
    public function botId(): int
    {
        $id = (int) ($this->app->settings->raw('_bot_id') ?? 0);
        if (!$id) {
            $me = $this->app->bale->call('getMe', [], 8);
            $id = (int) ($me['result']['id'] ?? 0);
            if ($id) {
                $this->app->settings->internal('_bot_id', (string) $id, $this->app->now());
            }
        }
        return $id;
    }

    /** Uses a channel the bot reported (forwarded post / added as admin): numeric id + link. */
    public function useChannel(string $id): array
    {
        $s = $this->app->settings;
        $chat = $this->app->bale->getChat($id);
        $username = (string) ($chat['result']['username'] ?? '');
        $s->set('required_channel', $username !== '' ? '@' . $username : $id, $this->app->now());
        if ($username !== '') {
            $s->internal('_channel_id', strtolower('@' . $username) . '=' . $id, $this->app->now());
            $s->set('channel_link', 'https://ble.ir/' . $username, $this->app->now());
        }
        $s->set('require_channel', '1', $this->app->now());
        return $this->diagnose();
    }

    /** Channels the bot was added to or a post was forwarded from (newest first, max 10). */
    public function knownChannels(): array
    {
        return json_decode((string) ($this->app->settings->raw('_channels') ?? '[]'), true) ?: [];
    }

    public function rememberChannel(array $chat, ?string $botStatus = null): void
    {
        $list = array_values(array_filter($this->knownChannels(), fn ($c) => (string) $c['id'] !== (string) $chat['id']));
        array_unshift($list, ['id' => (string) $chat['id'], 'title' => $chat['title'] ?? null, 'username' => $chat['username'] ?? null, 'bot_status' => $botStatus, 'at' => $this->app->now()]);
        $this->app->settings->internal('_channels', (string) json_encode(array_slice($list, 0, 10), JSON_UNESCAPED_UNICODE), $this->app->now());
    }
}
