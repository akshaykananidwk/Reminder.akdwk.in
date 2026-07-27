<?php
/**
 * Krishna Reminder — configuration template.
 *
 * This file is copied to config/config.php by the installer (/install).
 * Never edit config.php by hand unless you know what you are doing.
 */

return [
    'app' => [
        'name'        => '{{APP_NAME}}',
        'url'         => '{{APP_URL}}',
        'env'         => 'production',
        'debug'       => false,
        'timezone'    => '{{APP_TIMEZONE}}',
        'locale'      => '{{APP_LOCALE}}',
        'currency'    => '{{APP_CURRENCY}}',
        'key'         => '{{APP_KEY}}',      // 32-byte base64 encryption key
        'version'     => '1.0.0',
    ],

    'db' => [
        'host'    => '{{DB_HOST}}',
        'port'    => '{{DB_PORT}}',
        'name'    => '{{DB_NAME}}',
        'user'    => '{{DB_USER}}',
        'pass'    => '{{DB_PASS}}',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'cron_token'    => '{{CRON_TOKEN}}',
        'webhook_secret'=> '{{WEBHOOK_SECRET}}',
        'cookie_secure' => true,
    ],

    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'uploads' => __DIR__ . '/../uploads',
        'backups' => __DIR__ . '/../backups',
    ],
];
