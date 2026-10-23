<?php
// Host report for the panel: RAM, CPU load, disk, PHP, OPcache, MySQL, Bale webhook.
// Shared hosts often hide some of these (open_basedir, disabled functions, no access to
// /proc or SHOW STATUS): every probe is guarded and missing values show as "unknown".
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class ServerInfo
{
    public function __construct(private App $app)
    {
    }

    private static function can(string $fn): bool
    {
        static $disabled = null;
        $disabled ??= array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return function_exists($fn) && !in_array($fn, $disabled, true);
    }

    private static function read(string $file): ?string
    {
        try {
            $s = @is_readable($file) ? @file_get_contents($file) : false;
        } catch (\Throwable) {
            $s = false;
        }
        return $s === false ? null : $s;
    }

    /** @return array{cpus:?int,load:?array,load_pct:?float,mem_total:?int,mem_available:?int,mem_pct:?float,cgroup_limit:?int,cgroup_used:?int,uptime:?int,disk_total:?float,disk_free:?float,disk_pct:?float} */
    public function system(): array
    {
        $cpus = null;
        if ($info = self::read('/proc/cpuinfo')) {
            $cpus = max(1, preg_match_all('~^processor\s*:~m', $info));
        }
        $load = self::can('sys_getloadavg') ? (@sys_getloadavg() ?: null) : null;
        $mem = [];
        if ($m = self::read('/proc/meminfo')) {
            foreach (['MemTotal', 'MemAvailable', 'MemFree', 'Cached'] as $k) {
                if (preg_match('~^' . $k . ':\s+(\d+)~m', $m, $mm)) {
                    $mem[$k] = (int) $mm[1] * 1024;
                }
            }
        }
        $avail = $mem['MemAvailable'] ?? (isset($mem['MemFree']) ? $mem['MemFree'] + ($mem['Cached'] ?? 0) : null);
        // Container / CloudLinux limits for this account, when visible (cgroup v2, then v1).
        $limit = self::read('/sys/fs/cgroup/memory.max') ?? self::read('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        $used = self::read('/sys/fs/cgroup/memory.current') ?? self::read('/sys/fs/cgroup/memory/memory.usage_in_bytes');
        $limit = $limit !== null && ctype_digit(trim($limit)) && (int) $limit < (1 << 50) ? (int) $limit : null;
        $used = $used !== null && ctype_digit(trim($used)) ? (int) $used : null;
        $uptime = ($u = self::read('/proc/uptime')) ? (int) $u : null;
        $dir = dirname(__DIR__);
        $total = self::can('disk_total_space') ? (@disk_total_space($dir) ?: null) : null;
        $free = self::can('disk_free_space') ? (@disk_free_space($dir) ?: null) : null;
        return [
            'cpus'          => $cpus,
            'load'          => $load,
            'load_pct'      => $load && $cpus ? 100 * $load[0] / $cpus : null,
            'mem_total'     => $mem['MemTotal'] ?? null,
            'mem_available' => $avail,
            'mem_pct'       => isset($mem['MemTotal'], $avail) && $mem['MemTotal'] ? 100 * (1 - $avail / $mem['MemTotal']) : null,
            'cgroup_limit'  => $limit,
            'cgroup_used'   => $used,
            'uptime'        => $uptime,
            'disk_total'    => $total,
            'disk_free'     => $free,
            'disk_pct'      => $total && $free !== null ? 100 * (1 - $free / $total) : null,
        ];
    }

    public function php(): array
    {
        $op = self::can('opcache_get_status') ? (@opcache_get_status(false) ?: null) : null;
        $opMem = $op['memory_usage'] ?? null;
        return [
            'version'        => PHP_VERSION,
            'sapi'           => PHP_SAPI,
            'os'             => PHP_OS_FAMILY,
            'memory_limit'   => ini_get('memory_limit'),
            'max_execution'  => ini_get('max_execution_time'),
            'request_memory' => memory_get_peak_usage(true),
            'opcache'        => $op ? !empty($op['opcache_enabled']) : false,
            'opcache_used'   => $opMem ? $opMem['used_memory'] / max(1, $opMem['used_memory'] + $opMem['free_memory']) * 100 : null,
            'opcache_hit'    => $op['opcache_statistics']['opcache_hit_rate'] ?? null,
            'extensions'     => array_map(fn ($e) => [$e, extension_loaded($e)], ['curl', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'intl']),
            'timezone'       => $this->app->config['timezone'] ?? 'Asia/Tehran',
            'server'         => substr((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 0, 60),
        ];
    }

    /** MySQL version, size of our tables, connections (when the host allows SHOW STATUS). */
    public function database(): array
    {
        $db = $this->app->db;
        $out = ['version' => (string) $db->value('SELECT VERSION()'), 'tables' => [], 'size' => 0, 'status' => []];
        try {
            $rows = $db->all(
                'SELECT TABLE_NAME name, TABLE_ROWS rows_est, DATA_LENGTH + INDEX_LENGTH bytes FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY bytes DESC',
                [str_replace('_', '\\_', $db->prefix) . '%']
            );
            foreach ($rows as $r) {
                // Exact counts for small tables; InnoDB's estimate for big ones (COUNT(*) would be slow).
                $estimate = (int) $r['rows_est'] > 200000;
                $count = $estimate ? (int) $r['rows_est'] : (int) $db->pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $r['name']) . '`')->fetchColumn();
                $out['tables'][] = ['name' => $r['name'], 'rows' => $count, 'estimate' => $estimate, 'bytes' => (int) $r['bytes']];
                $out['size'] += (int) $r['bytes'];
            }
        } catch (\Throwable) {
        }
        try {
            foreach ($db->all("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Uptime','Questions','Slow_queries','Max_used_connections')") as $r) {
                $out['status'][$r['Variable_name']] = (int) $r['Value'];
            }
            $out['status']['max_connections'] = (int) $db->value('SELECT @@max_connections');
        } catch (\Throwable) {
        }
        return $out;
    }

    /** getWebhookInfo + how long a getMe round trip takes from this host. */
    public function bale(): array
    {
        $t = hrtime(true);
        $me = $this->app->bale->call('getMe', [], 8);
        $ping = (hrtime(true) - $t) / 1e6;
        $wh = $this->app->bale->call('getWebhookInfo', [], 8);
        $r = $wh['result'] ?? [];
        return [
            'ok'         => !empty($me['ok']),
            'username'   => $me['result']['username'] ?? null,
            'ping_ms'    => $ping,
            'url'        => preg_replace('~([?&]k=)[^&]+~', '$1***', (string) ($r['url'] ?? '')),
            'pending'    => $r['pending_update_count'] ?? null,
            'last_error' => $r['last_error_message'] ?? null,
            'last_error_at' => isset($r['last_error_date']) ? gmdate('Y-m-d H:i:s', (int) $r['last_error_date']) : null,
            'error'      => empty($me['ok']) ? (string) ($me['description'] ?? '') : null,
        ];
    }

    /** Size of storage/logs. */
    public function logs(): array
    {
        $dir = (string) ($this->app->config['log_dir'] ?? '');
        $files = $dir !== '' ? (glob($dir . '/*.log') ?: []) : [];
        return ['count' => count($files), 'bytes' => array_sum(array_map(fn ($f) => (int) @filesize($f), $files))];
    }
}
