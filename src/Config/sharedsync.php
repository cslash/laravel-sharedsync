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
        'composer' => true,
        'npm' => true,
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
        '.DS_Store',
        '.git',
        '.env',
        '.idea',
        'node_modules',
        'tests',
        'bootstrap/cache',
        'storage/app/public',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/framework/testing',
        'storage/logs/*',
        'storage/media-library/*',
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

    /*
    |--------------------------------------------------------------------------
    | Deployment URL
    |--------------------------------------------------------------------------
    |
    | The public URL of the deployed project. Used for post-deployment checks.
    |
    */
    'url' => env('SHAREDSYNC_URL'),
];
