<?php
// Copy to config.php and fill in. NEVER commit config.php (it is in .gitignore).
return [
    'database' => [
        'host'         => 'localhost',
        'dbname'       => 'YOUR_DB',
        'username'     => 'YOUR_DB_USER',
        'password'     => 'YOUR_DB_PASSWORD',
        'table_prefix' => 'rc_',            // new version later? use 'rc2_' → old tables stay for rollback
    ],
    'admin' => [
        'password' => 'CHANGE_ME_LONG_RANDOM',  // for migrate.php / check.php (?pass=…)
        'chat_id'  => 123456789,               // your Bale user id: admin alerts + admin commands
    ],
    'bale' => [
        'bot_token'    => 'YOUR_BOT_TOKEN',
        'bot_username' => 'your_bot',          // for referral links https://ble.ir/<username>?start=ref<id>
        'api_base'     => 'https://tapi.bale.ai',
        // Random string; check.php?setwebhook=1 adds it to the webhook URL and webhook.php
        // rejects requests without it, so nobody else can post fake updates to your bot.
        'webhook_secret' => 'CHANGE_ME_RANDOM_32_CHARS',
    ],
    'payment' => [
        'provider_token' => 'WALLET-XXXXXXXXXXXXXXXX', // Bale wallet token ("WALLET-TEST-1111111111111111" = test wallet)
        'debug'          => true,                     // admin gets a line for every accepted pre-checkout
        // Prices are in RIAL (238,000 toman = 2,380,000 rial).
        'packages' => [
            'week'  => ['title' => 'اشتراک ۷ روزه',  'description' => 'دسترسی ۷ روزه به امکانات ویژه',  'amount' => 490000,  'days' => 7],
            'month' => ['title' => 'اشتراک ۳۰ روزه', 'description' => 'دسترسی ۳۰ روزه به امکانات ویژه', 'amount' => 1490000, 'days' => 30],
        ],
    ],
    'support_username' => '@your_support',
];
