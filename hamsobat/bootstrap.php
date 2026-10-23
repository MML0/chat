<?php
// Loads every class. No Composer: plain PHP 8.2+ with curl, pdo_mysql, mbstring, json.
const HAMSOBAT = true;
const HAMSOBAT_VERSION = '1.1';

require_once __DIR__ . '/database.php';
// Order matters only for the Handler base class (services/ loads before handlers/ and admin/).
foreach (['services', 'handlers', 'admin'] as $dir) {
    foreach (glob(__DIR__ . "/{$dir}/*.php") as $file) {
        require_once $file;
    }
}

/** Reads config.php (or the file in HAMSOBAT_CONFIG, used by the tests). */
function hamsobat_config(): array
{
    $file = getenv('HAMSOBAT_CONFIG') ?: __DIR__ . '/config.php';
    if (!is_file($file)) {
        http_response_code(500);
        exit("config.php is missing: copy config-example.php to config.php and fill it in.\n");
    }
    return require $file;
}
