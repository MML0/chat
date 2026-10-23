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
        $channel = $this->app->settings->str('required_channel');
        $res = $this->app->bale->getChatMember($channel, (int) $user['bale_user_id']);
        if (!empty($res['ok'])) {
            $status = (string) ($res['result']['status'] ?? '');
            $member = in_array($status, ['creator', 'owner', 'administrator', 'member'], true)
                || ($status === 'restricted' && !empty($res['result']['is_member']));
            $this->app->users()->update((int) $user['id'], ['channel_verified_at' => $member ? $this->app->ts() : null]);
            return $member;
        }
        // "user not found / not a participant" simply means not a member. Anything else
        // (bot is not an admin, wrong channel id, network) is a setup problem for the admin.
        $desc = (string) ($res['description'] ?? '');
        if (preg_match('~user not found|not a member|participant|USER_NOT_PARTICIPANT~i', $desc)) {
            return false;
        }
        $this->app->log->warning('getChatMember failed', ['channel' => $channel, 'description' => $desc]);
        $this->app->alertAdminsOnce('channel_check', "⚠️ بررسی عضویت کانال {$channel} خطا داد:\n{$desc}\n\nربات باید ادمین کانال باشد. تنظیم: /setchannel");
        return $this->app->settings->bool('channel_check_fail_open');
    }
}
