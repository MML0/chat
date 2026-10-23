<?php

namespace App;

use PDO;

/**
 * Premium time per user, kept as rows (who granted it, why, until when) instead of one flag.
 *
 * Sources: 'payment' (Bale wallet, confirmed by inquireTransaction), 'admin' (manual, after
 * you checked a card-to-card payment), 'referral' (free). Paid-only features ask for
 * paidOnly = true, so free/referral time never unlocks them. Any error = no access.
 */
final class Premium
{
    public const PAYMENT  = 'payment';
    public const ADMIN    = 'admin';
    public const REFERRAL = 'referral';
    private const PAID_SOURCES = [self::PAYMENT, self::ADMIN];

    /** @var callable */
    private $now;

    public function __construct(private PDO $db, private string $table, ?callable $now = null)
    {
        $this->now = $now ?? fn () => time();
    }

    public function activeUntil(int $userId, bool $paidOnly = false): ?string
    {
        try {
            $now = date('Y-m-d H:i:s', ($this->now)());
            $sql = "SELECT MAX(expires_at) FROM {$this->table} WHERE user_id = ? AND status = 'active' AND starts_at <= ? AND expires_at > ?";
            $args = [$userId, $now, $now];
            if ($paidOnly) {
                $sql .= " AND source IN ('" . implode("','", self::PAID_SOURCES) . "')";
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($args);
            return $stmt->fetchColumn() ?: null;
        } catch (\Throwable $e) {
            error_log('[premium] check failed, denying: ' . $e->getMessage());
            return null; // fail closed
        }
    }

    public function isActive(int $userId, bool $paidOnly = false): bool
    {
        return $this->activeUntil($userId, $paidOnly) !== null;
    }

    /**
     * Adds $days after the user's current premium end (or now). Returns the new end.
     * A reference (receipt) is counted once: granting the same reference again changes nothing.
     */
    public function grant(int $userId, int $days, string $source, ?string $reference = null, int $grantedBy = 0): string
    {
        if ($reference !== null) {
            $stmt = $this->db->prepare("SELECT expires_at FROM {$this->table} WHERE reference = ? LIMIT 1");
            $stmt->execute([$reference]);
            if (($existing = $stmt->fetchColumn()) !== false) {
                return (string) $existing;
            }
        }
        $now = ($this->now)();
        $current = $this->activeUntil($userId);
        $start = $current ? max($now, strtotime($current)) : $now;
        $expires = date('Y-m-d H:i:s', $start + $days * 86400);
        $this->db->prepare(
            "INSERT INTO {$this->table} (user_id, source, reference, status, starts_at, expires_at, granted_by, created_at)
             VALUES (?, ?, ?, 'active', ?, ?, ?, ?)"
        )->execute([$userId, $source, $reference, date('Y-m-d H:i:s', $now), $expires, $grantedBy, date('Y-m-d H:i:s', $now)]);
        return $expires;
    }

    public function revoke(int $userId): int
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET status = 'cancelled' WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    public function hasReference(string $reference): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$this->table} WHERE reference IN (?, ?) AND status = 'active' LIMIT 1");
        $stmt->execute([$reference, strtolower($reference)]);
        return $stmt->fetchColumn() !== false;
    }

    /** "۳ روز و ۵ ساعت" style remaining time, or null. */
    public function remainingText(int $userId): ?string
    {
        $until = $this->activeUntil($userId);
        if (!$until) {
            return null;
        }
        $diff = strtotime($until) - ($this->now)();
        $days = intdiv($diff, 86400);
        $hours = intdiv($diff % 86400, 3600);
        $parts = [];
        if ($days > 0) {
            $parts[] = "{$days} روز";
        }
        if ($hours > 0) {
            $parts[] = "{$hours} ساعت";
        }
        return $parts ? implode(' و ', $parts) : 'کمتر از ۱ ساعت';
    }
}
