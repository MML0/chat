<?php
// Copy this file to config.php and fill it in. NEVER commit or share config.php.
//
// Every secret can also come from an environment variable (if your host has them):
// the value after the variable name is only used when the variable is not set.
$env = fn (string $name, $default = '') => getenv($name) !== false ? getenv($name) : $default;

return [
    'database' => [
        'host'         => $env('HS_DB_HOST', 'localhost'),
        'port'         => $env('HS_DB_PORT', ''),             // empty = default 3306
        'dbname'       => $env('HS_DB_NAME', 'YOUR_DB'),
        'username'     => $env('HS_DB_USER', 'YOUR_DB_USER'),
        'password'     => $env('HS_DB_PASSWORD', 'YOUR_DB_PASSWORD'),
        'table_prefix' => 'hs_',   // a new version later? use 'hs2_': old tables stay for rollback
    ],

    'bale' => [
        'bot_token'      => $env('HS_BOT_TOKEN', 'YOUR_BOT_TOKEN'),
        'bot_username'   => 'hamsohbatbot',                 // without @, used in the links
        'api_base'       => 'https://tapi.bale.ai',
        // Random 32+ characters. check.php?setwebhook=1 puts it in the webhook URL and
        // webhook.php refuses requests without it (nobody can post fake updates).
        'webhook_secret' => $env('HS_WEBHOOK_SECRET', 'CHANGE_ME_RANDOM_32_CHARS'),
    ],

    'admin' => [
        // For migrate.php / check.php (?pass=…). Long and random.
        'password' => $env('HS_ADMIN_PASSWORD', 'CHANGE_ME_LONG_RANDOM'),
        // Bale user ids of the admins (numbers). They get alerts and the admin commands.
        // Send /start to the bot, then open check.php: it lists the newest users with their ids.
        'ids'      => [123456789],
    ],

    // Secret for cron/cleanup.php?k=… and cron/daily_tasks.php?k=… (external cron services).
    'cron_secret' => $env('HS_CRON_SECRET', 'CHANGE_ME_RANDOM_CRON_SECRET'),

    // Admin panel: https://YOUR.SITE/hamsobat/panel/ (login = admin.password above).
    'panel' => [
        'two_factor'    => true,  // after the password, the bot sends a 6-digit code to admin.ids in Bale
        'session_hours' => 12,    // logged out after this long without activity
    ],

    'timezone' => 'Asia/Tehran',               // daily quotas reset at midnight in this timezone
    'log_dir'  => __DIR__ . '/storage/logs',   // also protected by storage/.htaccess

    // Starting values. The admin can change them later from inside Bale with /set
    // (the value saved there wins). The full list with explanations: /settings or services/Settings.php.
    'settings' => [
        'required_channel'        => '',   // e.g. '@hamsohbat_channel' (the bot must be an admin there)
        'channel_link'            => '',   // e.g. 'https://ble.ir/hamsohbat_channel'
        'daily_free_chats'        => 5,
        'referral_reward_credits' => 3,
        'search_timeout_seconds'  => 120,
        'min_age'                 => 18,
    ],
];
