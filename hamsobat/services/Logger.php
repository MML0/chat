<?php
// Structured error log: one JSON line per event in storage/logs/YYYY-MM-DD.log and,
// for warnings and errors, a row in {error_logs} so the admin can read them with /errors.
// Secrets (bot token, webhook secret, passwords) are redacted before anything is written.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Logger
{
    private array $secrets;

    public function __construct(private ?Database $db, private string $dir, array $secrets = [])
    {
        $this->secrets = array_values(array_filter(array_map('strval', $secrets), fn ($s) => strlen($s) >= 6));
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function redact(string $text): string
    {
        foreach ($this->secrets as $s) {
            $text = str_replace($s, '***', $text);
        }
        return preg_replace('~/bot[0-9]+:[A-Za-z0-9_-]+~', '/bot***', $text);
    }

    private function write(string $level, string $message, array $context): void
    {
        $message = $this->redact($message);
        $ctx = $context ? $this->redact((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)) : null;
        $line = json_encode(['t' => gmdate('c'), 'level' => $level, 'msg' => $message, 'ctx' => $context ? json_decode((string) $ctx, true) : null], JSON_UNESCAPED_UNICODE);
        if ($this->dir !== '' && (is_dir($this->dir) || @mkdir($this->dir, 0750, true))) {
            @file_put_contents($this->dir . '/' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
        } else {
            error_log('[hamsobat] ' . $line);
        }
        if ($level !== 'info' && $this->db) {
            try {
                $this->db->run('INSERT INTO {error_logs} (level, message, context, created_at) VALUES (?, ?, ?, ?)', [
                    $level, mb_substr($message, 0, 1000), $ctx !== null ? mb_substr($ctx, 0, 4000) : null, gmdate('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable) {
                // the file log above is enough
            }
        }
    }
}
