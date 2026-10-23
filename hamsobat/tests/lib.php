<?php
// Test helpers: a real MySQL/MariaDB test database, a fake Bale API and a clock we control.
// The database name must contain "test" or "e2e": every table with the t_ prefix is reset.
require_once dirname(__DIR__) . '/bootstrap.php';
date_default_timezone_set('UTC');

use Hamsobat\App;
use Hamsobat\BaleApi;
use Hamsobat\Database;
use Hamsobat\Router;
use Hamsobat\Ui;

function test_config(): array
{
    return [
        'database' => [
            'host'         => getenv('HS_TEST_DB_HOST') ?: '127.0.0.1',
            'port'         => getenv('HS_TEST_DB_PORT') ?: '3307',
            'dbname'       => getenv('HS_TEST_DB_NAME') ?: 'hs_test',
            'username'     => getenv('HS_TEST_DB_USER') ?: 'hs',
            'password'     => getenv('HS_TEST_DB_PASSWORD') ?: 'hs',
            'table_prefix' => 't_',
        ],
        'bale'        => ['bot_token' => '111:TESTTOKEN', 'bot_username' => 'hamsohbatbot', 'api_base' => 'https://tapi.bale.ai', 'webhook_secret' => 'test-webhook-secret'],
        'admin'       => ['password' => 'test-admin-password', 'ids' => [9000]],
        'cron_secret' => 'test-cron-secret',
        'timezone'    => 'Asia/Tehran',
        'log_dir'     => sys_get_temp_dir() . '/hamsobat-test-logs',
        'settings'    => [],
    ];
}

/** Drops and recreates the t_ tables once per test process. */
function test_schema(Database $db): void
{
    static $done = false;
    $name = (string) $db->value('SELECT DATABASE()');
    if (!preg_match('~test|e2e~', $name)) {
        throw new RuntimeException("refusing to reset database {$name}");
    }
    if (!$done) {
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all("SHOW TABLES LIKE 't\\_%'") as $row) {
            $db->pdo->exec('DROP TABLE `' . array_values($row)[0] . '`');
        }
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (Database::schema(dirname(__DIR__) . '/database.sql', 't_') as $sql) {
            $db->pdo->exec($sql);
        }
        $done = true;
        return;
    }
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($db->all("SHOW TABLES LIKE 't\\_%'") as $row) {
        $db->pdo->exec('TRUNCATE `' . array_values($row)[0] . '`');
    }
    $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $db->pdo->exec("INSERT IGNORE INTO t_locks (name) VALUES ('matching')");
}

/** Fake Bale: records every call and answers like Bale/Telegram would. */
final class FakeBale
{
    public array $calls = [];
    public array $members = [];      // bale user id => getChatMember status
    public array $unreachable = [];  // bale user ids that blocked the bot (403)
    public bool $channelBroken = false;
    public array $failMethods = [];  // method => description (ok:false)
    private int $messageId = 1000;

    public function transport(): callable
    {
        return function (string $url, $body, array $headers, int $timeout) {
            $method = substr($url, strrpos($url, '/') + 1);
            $isJson = in_array('Content-Type: application/json', $headers, true);
            $p = $isJson ? json_decode($body, true) : $body;
            if (!$isJson && isset($p['reply_markup'])) {
                $p['reply_markup'] = json_decode($p['reply_markup'], true);
            }
            $this->calls[] = ['method' => $method, 'p' => $p, 'json' => $isJson];
            if (isset($p['chat_id']) && in_array((int) $p['chat_id'], $this->unreachable, true) && $method !== 'getChatMember') {
                return [403, json_encode(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'])];
            }
            if (isset($this->failMethods[$method])) {
                return [400, json_encode(['ok' => false, 'error_code' => 400, 'description' => $this->failMethods[$method]])];
            }
            if ($method === 'getChatMember') {
                if ($this->channelBroken) {
                    return [400, json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'])];
                }
                $status = $this->members[(int) $p['user_id']] ?? 'left';
                return [200, json_encode(['ok' => true, 'result' => ['status' => $status, 'user' => ['id' => (int) $p['user_id']]]])];
            }
            if ($method === 'getMe') {
                return [200, json_encode(['ok' => true, 'result' => ['id' => 1, 'username' => 'hamsohbatbot']])];
            }
            return [200, json_encode(['ok' => true, 'result' => ['message_id' => ++$this->messageId]])];
        };
    }

    /** Calls whose chat_id is $chatId. */
    public function to(int $chatId): array
    {
        return array_values(array_filter($this->calls, fn ($c) => isset($c['p']['chat_id']) && (int) $c['p']['chat_id'] === $chatId));
    }

    /** Texts and captions sent to $chatId. */
    public function texts(int $chatId): array
    {
        return array_values(array_filter(array_map(fn ($c) => $c['p']['text'] ?? $c['p']['caption'] ?? null, $this->to($chatId)), fn ($t) => $t !== null));
    }

    public function lastText(int $chatId): string
    {
        $t = $this->texts($chatId);
        return (string) end($t);
    }

    public function of(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }
}

/** One test world: fresh tables, fake Bale, clock, and helpers that act like real users. */
final class World
{
    public App $app;
    public FakeBale $bale;
    public int $time;
    private int $updateId = 1;
    private int $messageId = 1;

    public function __construct(array $settings = [])
    {
        $config = test_config();
        $config['settings'] = $settings + ['require_channel' => 0];
        $this->bale = new FakeBale();
        $this->time = strtotime('2026-03-10 09:00:00 UTC'); // 12:30 in Tehran
        $db = Database::connect($config);
        test_schema($db);
        $this->app = new App($config, $db, new BaleApi('111:TESTTOKEN', 'https://tapi.bale.ai', $this->bale->transport()), fn () => $this->time);
    }

    public function handle(array $update): void
    {
        (new Router($this->app))->handle($update);
    }

    public function msg(int $from, string $text, array $extra = []): void
    {
        $this->handle(['update_id' => $this->updateId++, 'message' => [
            'message_id' => $this->messageId++, 'date' => $this->time,
            'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'U' . $from],
            'chat' => ['id' => $from, 'type' => 'private'],
            'text' => $text,
        ] + $extra]);
    }

    /** Media message, e.g. media(5, 'photo', [['file_id' => 'P1', 'file_size' => 100]]). */
    public function media(int $from, string $type, array $obj, string $caption = ''): void
    {
        $m = ['message_id' => $this->messageId++, 'date' => $this->time, 'from' => ['id' => $from, 'is_bot' => false], 'chat' => ['id' => $from, 'type' => 'private'], $type => $obj];
        if ($caption !== '') {
            $m['caption'] = $caption;
        }
        $this->handle(['update_id' => $this->updateId++, 'message' => $m]);
    }

    public function cb(int $from, string $data, int $messageId = 1): void
    {
        $this->handle(['update_id' => $this->updateId++, 'callback_query' => [
            'id' => 'cb' . $this->updateId, 'from' => ['id' => $from, 'is_bot' => false], 'data' => $data,
            'message' => ['message_id' => $messageId, 'chat' => ['id' => $from, 'type' => 'private']],
        ]]);
    }

    public function user(int $baleId): ?array
    {
        return $this->app->db->row('SELECT * FROM {users} WHERE bale_user_id = ?', [$baleId]);
    }

    /** Registers a user through the real conversation. */
    public function register(int $baleId, string $nick, string $gender = 'm', string $pref = 'any', string $birth = '1375/01/01', ?string $startPayload = null): array
    {
        $this->msg($baleId, '/start' . ($startPayload ? " {$startPayload}" : ''));
        $this->msg($baleId, Ui::COMPLETE_PROFILE);
        $this->msg($baleId, 'کاربر تست');
        $this->msg($baleId, $nick);
        $this->msg($baleId, $birth);
        $this->cb($baleId, "rg:g:{$gender}");
        $this->cb($baleId, "rg:p:{$pref}");
        foreach (['photo', 'city', 'bio', 'interests'] as $step) {
            $this->cb($baleId, "rg:skip:{$step}");
        }
        return $this->user($baleId);
    }

    /** Registered + allowed to chat without a referral. */
    public function ready(int $baleId, string $nick, string $gender = 'm', string $pref = 'any', string $birth = '1375/01/01'): array
    {
        $u = $this->register($baleId, $nick, $gender, $pref, $birth);
        $this->app->db->run('UPDATE {users} SET referral_waived = 1 WHERE id = ?', [$u['id']]);
        return $this->user($baleId);
    }

    public function activeChat(int $baleId): ?array
    {
        $u = $this->user($baleId);
        return $u ? $this->app->matching()->activeChat((int) $u['id']) : null;
    }

    public function count(string $sql, array $p = []): int
    {
        return (int) $this->app->db->value($sql, $p);
    }
}

// ------------------------------------------------------------------ tiny test runner

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = [];

function ok($cond, string $msg): void
{
    if ($cond) {
        $GLOBALS['pass']++;
    } else {
        $GLOBALS['fail'][] = $GLOBALS['current'] . ': ' . $msg;
    }
}

function last(array $a): mixed
{
    return $a ? $a[array_key_last($a)] : null;
}

function contains(string $haystack, string $needle): bool
{
    return str_contains($haystack, $needle);
}

function test(string $name, callable $fn): void
{
    $GLOBALS['current'] = $name;
    $before = count($GLOBALS['fail']);
    try {
        $fn();
    } catch (Throwable $e) {
        $GLOBALS['fail'][] = "{$name}: " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    }
    echo (count($GLOBALS['fail']) === $before ? '  ✔ ' : '  ✘ ') . $name . "\n";
}
