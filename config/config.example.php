<?php
declare(strict_types=1);

/**
 * Copy to config/config.php and adjust. Never commit config/config.php.
 */
return [
    'app' => [
        'name'        => 'DiarDrive',
        'debug'       => false,
        'timezone'    => 'Asia/Jakarta',
        'base_path'   => null,       // null = auto-detect
        'trust_proxy' => true,       // behind Cloudflare Tunnel
        'token_ttl'   => 31536000,   // 1 year
        'storage_root' => '/home/diardrive/storage',
    ],

    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3307;dbname=diardrive;charset=utf8mb4',
        'user' => 'diardrive',
        'pass' => 'YOUR_DB_PASSWORD_HERE',
    ],

    'upload' => [
        'max_bytes' => 536870912,    // 512 MB
    ],
];
