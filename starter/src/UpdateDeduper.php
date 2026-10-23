<?php

namespace App;

use PDO;

/**
 * Bale re-delivers an update when the webhook answers slowly or with an error.
 * Remember every processed update for a day so nothing (above all a payment) runs twice.
 * The key is update_id + a hash of the body, so two different updates can never collide.
 */
final class UpdateDeduper
{
    public function __construct(private PDO $db, private string $table)
    {
    }

    public function firstSeen(array $update, string $rawBody): bool
    {
        if (!isset($update['update_id'])) {
            return true;
        }
        $key = $update['update_id'] . ':' . md5($rawBody);
        try {
            $this->db->prepare("INSERT INTO {$this->table} (update_key, seen_at) VALUES (?, ?)")->execute([$key, date('Y-m-d H:i:s')]);
        } catch (\PDOException $e) {
            if (Db::isDuplicate($e)) {
                return false;
            }
            return true; // never block the bot because the dedupe table has a problem
        }
        if (random_int(1, 200) === 1) { // cheap housekeeping
            $this->db->prepare("DELETE FROM {$this->table} WHERE seen_at < ?")->execute([date('Y-m-d H:i:s', time() - 86400)]);
        }
        return true;
    }
}
