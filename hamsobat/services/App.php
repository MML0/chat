<?php
// Everything a request needs: config, DB, Bale client, settings, clock and the services.
// Tests build it with a fake Bale transport and a controllable clock.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class App
{
    public readonly Settings $settings;
    public readonly Logger $log;
    private array $services = [];
    /** @var callable(): int */
    private $clock;

    public function __construct(public readonly array $config, public readonly Database $db, public readonly BaleApi $bale, ?callable $clock = null)
    {
        $this->clock = $clock ?? 'time';
        $this->settings = new Settings($db, $config['settings'] ?? []);
        $this->log = new Logger($db, (string) ($config['log_dir'] ?? ''), [
            $config['bale']['bot_token'] ?? '', $config['bale']['webhook_secret'] ?? '',
            $config['admin']['password'] ?? '', $config['cron_secret'] ?? '', $config['database']['password'] ?? '',
        ]);
    }

    public static function fromConfig(array $config): self
    {
        return new self(
            $config,
            Database::connect($config),
            new BaleApi((string) $config['bale']['bot_token'], (string) ($config['bale']['api_base'] ?? 'https://tapi.bale.ai'))
        );
    }

    // ------------------------------------------------------------------ time

    public function now(): int
    {
        return ($this->clock)();
    }

    /** UTC DATETIME string for the DB. */
    public function ts(?int $time = null): string
    {
        return gmdate('Y-m-d H:i:s', $time ?? $this->now());
    }

    /** Today (Y-m-d) in the app's timezone: daily quotas and ages use this. */
    public function today(?int $time = null): string
    {
        return (new \DateTimeImmutable('@' . ($time ?? $this->now())))
            ->setTimezone(new \DateTimeZone($this->config['timezone'] ?? 'Asia/Tehran'))
            ->format('Y-m-d');
    }

    // ------------------------------------------------------------------ people

    public function isAdmin(int $baleUserId): bool
    {
        return in_array($baleUserId, array_map('intval', (array) ($this->config['admin']['ids'] ?? [])), true);
    }

    public function notifyAdmins(string $text): void
    {
        foreach ((array) ($this->config['admin']['ids'] ?? []) as $id) {
            $this->bale->send((int) $id, mb_substr($this->log->redact($text), 0, 3500));
        }
    }

    /** Admin alert at most once per $every seconds per $key (e.g. a broken channel check). */
    public function alertAdminsOnce(string $key, string $text, int $every = 3600): void
    {
        if ($this->settings->claim('_alert_' . $key, $every, $this->now())) {
            $this->notifyAdmins($text);
        }
    }

    public function botUsername(): string
    {
        return ltrim((string) ($this->config['bale']['bot_username'] ?? ''), '@');
    }

    public function deepLink(string $payload): string
    {
        return 'https://ble.ir/' . $this->botUsername() . '?start=' . $payload;
    }

    // ------------------------------------------------------------------ services (one per request)

    public function users(): Users
    {
        return $this->services[Users::class] ??= new Users($this);
    }

    public function quota(): QuotaService
    {
        return $this->services[QuotaService::class] ??= new QuotaService($this);
    }

    public function matching(): MatchingService
    {
        return $this->services[MatchingService::class] ??= new MatchingService($this);
    }

    public function referrals(): ReferralService
    {
        return $this->services[ReferralService::class] ??= new ReferralService($this);
    }

    public function requirements(): Requirements
    {
        return $this->services[Requirements::class] ??= new Requirements($this);
    }

    public function relay(): MessageRelay
    {
        return $this->services[MessageRelay::class] ??= new MessageRelay($this);
    }

    public function moderation(): Moderation
    {
        return $this->services[Moderation::class] ??= new Moderation($this);
    }

    public function rate(): RateLimiter
    {
        return $this->services[RateLimiter::class] ??= new RateLimiter($this);
    }

    public function maintenance(): Maintenance
    {
        return $this->services[Maintenance::class] ??= new Maintenance($this);
    }

    /** Handlers are created on first use: $app->handler(ChatHandler::class). */
    public function handler(string $class): object
    {
        return $this->services[$class] ??= new $class($this);
    }
}
