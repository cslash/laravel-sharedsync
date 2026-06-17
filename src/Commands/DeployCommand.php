<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Cslash\SharedSync\Services\Builder;
use Cslash\SharedSync\Services\FileScanner;
use Cslash\SharedSync\Services\Manifest;
use Cslash\SharedSync\Services\Uploader\UploaderInterface;
use Cslash\SharedSync\Services\VendorManager;

class DeployCommand extends Command
{
    use InteractsWithUploader, RunsRemoteChecks;

    protected $signature = 'sharedsync:deploy 
                            {--dry-run : Only show what would be uploaded}
                            {--force : Ignore manifest and upload everything}
                            {--only= : Only upload specific folders (comma separated)}
                            {--skip-vendor : Do not build or deploy the vendor directory}
                            {--force-vendor : Force rebuild and redeployment of the vendor directory even if composer.lock has not changed}
                            {--remote-composer : Skip uploading vendor and run composer install on the remote server instead}';

    protected $description = 'Deploy Laravel project via FTP/SFTP';

    public function handle()
    {
        $startTime = microtime(true);
        $config = config('sharedsync');

        if (empty($config)) {
            $this->error('SharedSync configuration not found. Please run: php artisan vendor:publish --tag=sharedsync-config');
            return 1;
        }

        $this->info('Starting SharedSync Deployment...');

        $buildPath = base_path();
        $builder = null;

        $skipVendor = $this->option('skip-vendor') || $this->option('remote-composer');

        // Decide whether vendor needs building/deploying based on composer.lock changes
        $manifest = new Manifest(base_path());
        $previousMeta = $manifest->getMeta();
        $composerLockPath = base_path('composer.lock');
        $composerLockHash = file_exists($composerLockPath) ? md5_file($composerLockPath) : null;
        $composerLockChanged = ($previousMeta['composer_lock'] ?? null) !== $composerLockHash;
        $buildVendor = !$skipVendor && ($composerLockChanged || $this->option('force') || $this->option('force-vendor'));

        try {
            // 1. Build
            if (!$this->option('dry-run')) {
                $buildConfig = $config['build'];
                if (!$buildVendor) {
                    $buildConfig['composer'] = false;
                    if ($skipVendor) {
                        $this->info('Vendor deployment is disabled (--skip-vendor or --remote-composer). Skipping composer install in build.');
                    } else {
                        $this->info('composer.lock unchanged; skipping composer install in build.');
                    }
                }
                $builder = new Builder($buildConfig, base_path(), $this->output);
                $buildPath = $builder->build();
            } else {
                $this->warn('Skipping build in dry-run mode.');
            }

            // 2. Scan
            $this->info('Scanning files...');
            // Vendor is always handled separately (zip + extract or remote composer)
            // through VendorManager, so exclude it from the regular file scan/upload.
            $ignoreList = array_merge($config['ignore'], ['vendor']);
            $scanner = new FileScanner($buildPath, $ignoreList);
            $allFiles = $scanner->scan();

            // Filter by --only
            if ($this->option('only')) {
                $only = explode(',', $this->option('only'));
                $allFiles = array_filter($allFiles, function ($file) use ($only) {
                    foreach ($only as $path) {
                        if (str_starts_with($file['path'], trim($path))) {
                            return true;
                        }
                    }
                    return false;
                });
            }

            // 3. Manifest Comparison
            $lastManifestData = $this->option('force') ? [] : $manifest->load();
            unset($lastManifestData['__meta__']);

            // Vendor is never scanned by the regular flow, so preserve any previous
            // vendor manifest entries to avoid flagging them for deletion.
            $preservedVendorEntries = [];
            foreach ($lastManifestData as $p => $meta) {
                if ($p === 'vendor' || str_starts_with($p, 'vendor/')) {
                    $preservedVendorEntries[$p] = $meta;
                    unset($lastManifestData[$p]);
                }
            }

            $diff = $manifest->compare($allFiles, $lastManifestData);
            $toUpload = $diff['upload'];
            $toDelete = $config['options']['delete_removed'] ? $diff['delete'] : [];

            if (empty($toUpload) && empty($toDelete)) {
                $this->info('Everything is up to date.');
                return 0;
            }

            $this->table(
                ['Action', 'Count'],
                [
                    ['Upload/Update', count($toUpload)],
                    ['Delete', count($toDelete)],
                    ['Total Files Scanned', count($allFiles)],
                ]
            );

            if ($this->option('dry-run')) {
                $this->warn('Dry-run: No files were changed.');
                return 0;
            }

            // 4. Upload
            $uploader = $this->getUploader($config, $buildPath);
            $uploader->connect();

            if (!empty($toUpload)) {
                $this->info('Uploading files...');
                $uploader->upload($toUpload);
            }

            if (!empty($toDelete)) {
                $this->warn(sprintf('The following %d file(s) will be deleted from the remote server:', count($toDelete)));
                foreach ($toDelete as $deletePath) {
                    $this->line(' - ' . $deletePath);
                }

                if (!$this->confirm('Do you want to proceed with deleting these files?', false)) {
                    $this->info('Skipping deletion of removed files.');
                    $toDelete = [];
                } else {
                    $this->info('Deleting removed files...');
                    $uploader->delete($toDelete);
                }
            }

            // 5. Vendor deployment (handled outside Laravel, via uploaded PHP stub).
            if ($this->option('remote-composer')) {
                $this->runRemoteVendor($config, $uploader, 'composer');
            } elseif ($buildVendor) {
                $localVendorDir = $buildPath . DIRECTORY_SEPARATOR . 'vendor';
                $this->runRemoteVendor($config, $uploader, 'extract', $localVendorDir);
            }

            // 6. Save Manifest
            $this->info('Updating manifest...');
            $filesForManifest = $allFiles;
            foreach ($preservedVendorEntries as $path => $meta) {
                $filesForManifest[] = [
                    'path' => $path,
                    'hash' => $meta['hash'] ?? '',
                    'mtime' => $meta['mtime'] ?? 0,
                ];
            }
            $manifest->save($filesForManifest, [
                'composer_lock' => $composerLockHash,
            ]);

            // 7. Remote Checks
            $this->runRemoteChecks($config, $uploader);

            $uploader->disconnect();

            $duration = round(microtime(true) - $startTime, 2);
            $this->info("Deployment finished successfully in {$duration} seconds!");

            return 0;

        } catch (\Exception $e) {
            $this->error("Deployment failed: " . $e->getMessage());
            return 1;
        } finally {
            if ($builder) {
                $builder->cleanup();
            }
        }
    }

    /**
     * Deploy the vendor directory (or trigger remote composer install) via a
     * one-shot PHP stub uploaded to public/. The stub runs entirely outside
     * Laravel so it works even when vendor/ is missing or incomplete.
     */
    protected function runRemoteVendor(array $config, UploaderInterface $uploader, string $mode, ?string $localVendorDir = null): void
    {
        if (empty($config['url'])) {
            $this->warn('No deployment URL configured. Cannot manage vendor remotely.');
            return;
        }

        $manager = new VendorManager($uploader, $config['url'], $this->output);

        if ($mode === 'composer') {
            $this->info('Running composer install on the remote server...');
            if ($manager->runComposer()) {
                $this->info('Remote composer install completed.');
            }
            return;
        }

        if ($mode === 'extract' && $localVendorDir !== null) {
            $this->info('Deploying vendor directory (zip + remote extraction)...');
            if ($manager->deployVendor($localVendorDir)) {
                $this->info('Remote vendor deployment completed.');
            }
        }
    }

}
