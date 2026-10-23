<?php
// Admin panel login. The panel shows private chats, so it is locked down:
//   1. the admin password from config.php (compared with hash_equals)
//   2. a 6-digit code the bot sends to the admins' Bale accounts (panel.two_factor)
//   3. 5 wrong tries per IP per 15 minutes, session cookie HttpOnly + SameSite=Strict,
//      idle timeout, CSRF token on every form, and an audit log of every action.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class PanelAuth
{
    private const CODE_TTL = 300;
    private const MAX_FAILS = 5;
    private const FAIL_WINDOW = 900;

    public function __construct(private App $app)
    {
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('hs_panel');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    private function config(string $key, mixed $default): mixed
    {
        return $this->app->config['panel'][$key] ?? $default;
    }

    public function twoFactor(): bool
    {
        $ids = array_filter(array_map('intval', (array) ($this->app->config['admin']['ids'] ?? [])), fn ($id) => $id > 0 && $id !== 123456789);
        return (bool) $this->config('two_factor', true) && $ids;
    }

    public function loggedIn(): bool
    {
        $s = $_SESSION['auth'] ?? null;
        $idle = 3600 * (int) $this->config('session_hours', 12);
        if (!$s || ($s['at'] ?? 0) < time() - $idle || ($s['ua'] ?? '') !== $this->agent()) {
            unset($_SESSION['auth']);
            return false;
        }
        $_SESSION['auth']['at'] = time();
        return true;
    }

    /** Step 1. @return string|null Persian error, or null when the password was right. */
    public function checkPassword(string $password): ?string
    {
        if ($this->blocked()) {
            return '⛔ تلاش زیاد. ۱۵ دقیقه دیگه دوباره امتحان کن.';
        }
        $real = (string) ($this->app->config['admin']['password'] ?? '');
        if ($real === '' || preg_match('~CHANGE_ME~', $real) || !hash_equals($real, $password)) {
            $this->fail();
            $this->audit('login_failed', null, 'wrong password');
            return '❌ رمز اشتباهه.';
        }
        if (!$this->twoFactor()) {
            $this->finish();
            return null;
        }
        $code = (string) random_int(100000, 999999);
        $_SESSION['pending'] = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'until' => time() + self::CODE_TTL, 'tries' => 0];
        $sent = 0;
        foreach ((array) $this->app->config['admin']['ids'] as $id) {
            $res = $this->app->bale->send((int) $id, "🔐 کد ورود به پنل مدیریت هم‌صحبت:\n\n" . $code . "\n\n⏳ تا ۵ دقیقه معتبره.\n🌐 IP: " . $this->ip() . "\n\nاگه خودت نبودی، رمز ادمین رو در config.php عوض کن.");
            $sent += !empty($res['ok']) ? 1 : 0;
        }
        if (!$sent) {
            unset($_SESSION['pending']);
            return '❌ کد ورود به بله ارسال نشد (توکن یا اینترنت هاست را بررسی کن). برای ورود بدون کد: config.php → panel → two_factor = false';
        }
        return null;
    }

    public function awaitingCode(): bool
    {
        return isset($_SESSION['pending']) && $_SESSION['pending']['until'] > time();
    }

    /** Step 2. @return string|null Persian error, or null when logged in. */
    public function checkCode(string $code): ?string
    {
        $p = $_SESSION['pending'] ?? null;
        if (!$p || $p['until'] < time() || $p['tries'] >= 5) {
            unset($_SESSION['pending']);
            return '⌛ کد منقضی شد. دوباره وارد شو.';
        }
        $_SESSION['pending']['tries']++;
        if (!password_verify(trim(Text::digits($code)), $p['hash'])) {
            $this->fail();
            return '❌ کد اشتباهه.';
        }
        unset($_SESSION['pending']);
        $this->finish();
        return null;
    }

    public function logout(): void
    {
        $this->audit('logout');
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    }

    public function checkCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    public function audit(string $action, ?string $target = null, ?string $details = null): void
    {
        try {
            $this->app->db->insert('admin_log', [
                'action'     => $action,
                'target'     => $target !== null ? mb_substr($target, 0, 64) : null,
                'details'    => $details !== null ? mb_substr($details, 0, 500) : null,
                'ip'         => $this->ip(),
                'created_at' => $this->app->ts(),
            ]);
        } catch (\Throwable) {
            // table missing until migrate.php runs
        }
    }

    public function ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
    }

    private function finish(): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = ['at' => time(), 'since' => time(), 'ua' => $this->agent()];
        $this->audit('login');
    }

    private function agent(): string
    {
        return substr(md5((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12);
    }

    private function ipKey(): int
    {
        return crc32('panel:' . $this->ip()) & 0x7fffffff;
    }

    private function blocked(): bool
    {
        $r = $this->app->db->row("SELECT window_start, hits FROM {rate_limits} WHERE user_id = ? AND bucket = 'panel_login'", [$this->ipKey()]);
        return $r && (int) $r['hits'] >= self::MAX_FAILS && (int) $r['window_start'] > $this->app->now() - self::FAIL_WINDOW;
    }

    private function fail(): void
    {
        $this->app->rate()->hit($this->ipKey(), 'panel_login', self::FAIL_WINDOW);
    }
}
