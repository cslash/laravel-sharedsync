<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Deployment Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "ftp", "sftp"
    |
    */
    'driver' => env('SHAREDSYNC_DRIVER', 'ftp'),

    /*
    |--------------------------------------------------------------------------
    | FTP Configuration
    |--------------------------------------------------------------------------
    */
    'ftp' => [
        'host' => env('FTP_HOST'),
        'username' => env('FTP_USER'),
        'password' => env('FTP_PASS'),
        'port' => env('FTP_PORT', 21),
        'root' => env('FTP_ROOT', '/'),
        'passive' => env('FTP_PASSIVE', true),
        'ssl' => env('FTP_SSL', false),
        'timeout' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | SFTP Configuration
    |--------------------------------------------------------------------------
    */
    'sftp' => [
        'host' => env('SFTP_HOST'),
        'username' => env('SFTP_USER'),
        'password' => env('SFTP_PASS'),
        'port' => env('SFTP_PORT', 22),
        'root' => env('SFTP_ROOT', '/'),
        'privateKey' => env('SFTP_PRIVATE_KEY'),
        'timeout' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Build Configuration
    |--------------------------------------------------------------------------
    |
    | These steps are executed locally before deployment.
    |
    */
    'build' => [
        'composer' => false, // Set to true if you want to run composer install --no-dev locally before upload
        'npm' => true,
        'npm_command' => 'ci', // 'ci' for deterministic builds (requires package-lock.json), or 'install'
        'artisan_cache' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore Rules
    |--------------------------------------------------------------------------
    |
    | Files and directories that should be excluded from deployment.
    |
    */
    'ignore' => [
        '.git',
        '.env',
        'node_modules',
        'tests',
        'storage/logs/*',
        '.deploy-manifest.json',
        '.deployignore',
        'vendor/bin',
        'vendor/phpunit',
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Options
    |--------------------------------------------------------------------------
    */
    'options' => [
        'delete_removed' => true,
    ],
];
