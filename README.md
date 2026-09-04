# Laravel SharedSync

[![Latest Version on Packagist](https://img.shields.io/packagist/v/cslash/laravel-sharedsync.svg?style=flat-square)](https://packagist.org/packages/cslash/laravel-sharedsync)
[![Total Downloads](https://img.shields.io/packagist/dt/cslash/laravel-sharedsync.svg?style=flat-square)](https://packagist.org/packages/cslash/laravel-sharedsync)
[![Software License](https://img.shields.io/packagist/l/cslash/laravel-sharedsync.svg?style=flat-square)](LICENSE)

SharedSync is a Laravel package designed for deploying applications to shared hosting 
environments where only FTP or SFTP access is available. 
It builds the project locally and performs incremental uploads to the remote server.

This package is aimed at Laravel developers who want to deploy their applications to 
shared hosting environments that only support FTP or SFTP 
(a notable example is OVH's shared hosting basic plan).

## Features

- **Local Pre-Build**: Build project locally in an isolated temporary directory (Composer, NPM, Artisan cache).
- **Incremental Deployment**: Tracks changes using a manifest file (`.deploy-manifest.json`) and only uploads modified files.
- **Dedicated Vendor Management**: Fast ZIP-based remote deployment and extraction for the `vendor` directory.
- **FTP & SFTP Support**: Works seamlessly over standard FTP or secure SFTP connections.
- **Configurable Ignore Rules**: Customizable ignore patterns with `.deployignore` file support.
- **Dry-Run Mode**: Preview uploaded and deleted files before applying any remote changes.
- **Selective Deployment**: Deploy only specific directories using the `--only` option.
- **Remote Migrations**: Trigger remote database migrations securely via signed URLs.
- **Remote Health Checks**: Verify storage permissions and symbolic links post-deployment.

## Requirements

- PHP 8.2+ (with `ext-zip` enabled)
- Laravel 10.0, 11.0, or 12.0
- FTP or SFTP access to your hosting provider

## Installation

You can install the package via Composer:

```bash
composer require cslash/laravel-sharedsync
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=sharedsync-config
```

## Configuration

Edit `config/sharedsync.php` with your server details or use environment variables.

Example `.env` configuration:

```env
SHAREDSYNC_DRIVER=ftp

FTP_HOST=ftp.example.com
FTP_USER=user@example.com
FTP_PASS=secret
FTP_ROOT=/public_html
FTP_PASSIVE=true
FTP_SSL=false

SFTP_HOST=sftp.example.com
SFTP_USER=user
SFTP_PASS=secret
SFTP_ROOT=/var/www/html
SFTP_PRIVATE_KEY=/path/to/id_rsa

SHAREDSYNC_URL=https://example.com
```

Example `config/sharedsync.php`:

```php
'driver' => env('SHAREDSYNC_DRIVER', 'ftp'),

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

'sftp' => [
    'host' => env('SFTP_HOST'),
    'username' => env('SFTP_USER'),
    'password' => env('SFTP_PASS'),
    'port' => env('SFTP_PORT', 22),
    'root' => env('SFTP_ROOT', '/'),
    'privateKey' => env('SFTP_PRIVATE_KEY'),
    'timeout' => 90,
],

'build' => [
    'composer' => true,
    'npm' => true,
    'artisan_cache' => true,
],

'options' => [
    'delete_removed' => true,
],

'url' => env('SHAREDSYNC_URL'),
```

## Important Note on Local Build

The `composer` build step runs `composer install --no-dev --optimize-autoloader` in an isolated temporary 
directory. This ensures that your local development environment's `vendor` folder remains untouched 
and the current Artisan process is not affected by the removal of dev-dependencies.

This allows you to safely enable the `composer` build step in your configuration.

## Usage

### Basic Deployment

Deploy your application:

```bash
php artisan sharedsync:deploy
```

### Dry Run

Preview which files will be uploaded or deleted without making changes on the remote server:

```bash
php artisan sharedsync:deploy --dry-run
```

### Force Deployment

Ignore the previous manifest and upload all files:

```bash
php artisan sharedsync:deploy --force
```

### Selective Deployment

Only upload files from specific directories (comma-separated):

```bash
php artisan sharedsync:deploy --only=app,config,resources/views
```

### Test Connection

Test the connection to your remote server:

```bash
php artisan sharedsync:test
```

### List Remote Files

List files on the remote server:

```bash
php artisan sharedsync:ls
```

Or list a specific remote directory:

```bash
php artisan sharedsync:ls path/to/directory
```

### Show Deployment Diff

List files that will be uploaded or updated:

```bash
php artisan sharedsync:diff
```

Include unchanged files in the listing:

```bash
php artisan sharedsync:diff --all
```

Paginate the output:

```bash
php artisan sharedsync:diff --limit=50
```

### Vendor Management

Deploying thousands of vendor files one-by-one over FTP/SFTP can be slow and prone to connection timeouts or incomplete transfers. SharedSync provides a dedicated vendor management command to inspect dependencies and deploy the `vendor` directory as a compressed archive that gets extracted directly on the remote server.

```bash
php artisan sharedsync:vendor {action=list}
```

#### Available Actions:

- **List installed packages**:
  ```bash
  php artisan sharedsync:vendor list
  ```
  Lists packages and versions resolved in `composer.lock`.

- **Compare `composer.json` and `composer.lock`**:
  ```bash
  php artisan sharedsync:vendor diff
  ```
  Checks if dependencies in `composer.json` match the versions locked in `composer.lock`.

- **Deploy vendor directory**:
  ```bash
  php artisan sharedsync:vendor deploy
  ```
  Performs an optimized remote vendor deployment:
  1. Installs production dependencies in a clean, isolated local temporary directory (`composer install --no-dev --optimize-autoloader`).
  2. Compresses the resulting `vendor` folder into a temporary ZIP archive.
  3. Uploads the ZIP archive to the remote storage directory (`storage/sharedsync/`).
  4. Uploads a temporary standalone PHP controller script to the remote public directory.
  5. Requests the controller via HTTP to extract the ZIP archive into the remote `vendor/` directory.
  6. Automatically deletes the remote controller script and archive upon completion.

### Remote Database Migrations

Run database migrations on the remote server:

```bash
php artisan sharedsync:migrate
```

This command generates a temporary signed URL to securely trigger the migration on the remote server. For this to work, both your local and remote environments must share the same `APP_KEY`.

### Remote Health Checks

Run health checks on the remote server to verify storage permissions and ensure symlinks (such as `public/storage`) are in place:

```bash
php artisan sharedsync:check
```

These checks are also automatically executed at the end of every successful deployment.

## How It Works

1. **Build**: Creates an isolated temporary directory, copies the project (excluding `vendor`, `node_modules`, `.git`), and optionally runs `composer install --no-dev`, `npm install`, `npm run build`, and Artisan caching.
2. **Scan**: Recursively scans the build directory, applying rules from `config/sharedsync.php` and `.deployignore`.
3. **Compare**: Compares scanned files against the last deployment manifest (`.deploy-manifest.json`).
4. **Upload**: Connects via FTP/SFTP and incrementally uploads new or modified files from the build directory.
5. **Delete**: Removes remote files that no longer exist in the local build (if `delete_removed` is enabled).
6. **Manifest**: Saves the updated `.deploy-manifest.json` file locally.
7. **Remote Checks**: Connects to the remote `/sharedsync` endpoint (secured with a temporary token) to verify storage permissions and symlinks.
8. **Cleanup**: Deletes local temporary build directories and the remote security token.

## License

The MIT License (MIT).
