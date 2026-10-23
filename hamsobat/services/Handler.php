<?php
// Base class of the handlers in handlers/ and admin/.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

abstract class Handler
{
    public function __construct(protected App $app)
    {
    }

    /**
     * Inline button pressed. Return a short toast text, ['text' => …, 'alert' => true]
     * or null. The router answers the callback exactly once with it.
     */
    public function onCallback(array $user, string $data, array $cb): string|array|null
    {
        return null;
    }

    protected function say(array $user, string $text, ?array $markup = null): array
    {
        return $this->app->bale->send((int) $user['bale_user_id'], $text, $markup);
    }

    /** The reply keyboard that fits where the user is: chatting, searching, menu or onboarding. */
    protected function keyboardFor(array $user): array
    {
        $id = (int) $user['id'];
        if ($this->app->matching()->activeChat($id)) {
            return Ui::chatKeyboard();
        }
        if ($this->app->matching()->isSearching($id)) {
            return Ui::searchingKeyboard();
        }
        return $user['profile_completed'] ? Ui::mainKeyboard() : Ui::onboardingKeyboard();
    }

    protected function home(array $user, string $text = '🏠 منوی اصلی'): void
    {
        $this->say($user, $text, $this->keyboardFor($user));
    }

    protected function fresh(array $user): array
    {
        return $this->app->users()->get((int) $user['id']) ?? $user;
    }

    /** Edits the message the button belongs to; sends a new one if editing fails. */
    protected function editOrSend(array $user, array $cb, string $text, ?array $markup = null): void
    {
        $msgId = (int) ($cb['message']['message_id'] ?? 0);
        if ($msgId && empty($cb['message']['photo'])) {
            $res = $this->app->bale->editText((int) $user['bale_user_id'], $msgId, $text, $markup);
            if (!empty($res['ok']) || str_contains((string) ($res['description'] ?? ''), 'not modified')) {
                return;
            }
        }
        $this->say($user, $text, $markup);
    }
}
