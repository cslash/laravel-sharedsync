<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Cslash\SharedSync\Services\Builder;
use Cslash\SharedSync\Services\FileScanner;
use Cslash\SharedSync\Services\Manifest;
use Cslash\SharedSync\Services\Uploader\FtpUploader;
use Cslash\SharedSync\Services\Uploader\SftpUploader;
use Cslash\SharedSync\Services\Uploader\UploaderInterface;

class DeployCommand extends Command
{
    protected $signature = 'sharedsync:deploy 
                            {--dry-run : Only show what would be uploaded}
                            {--force : Ignore manifest and upload everything}
                            {--only= : Only upload specific folders (comma separated)}';

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

        // 1. Build
        if (!$this->option('dry-run')) {
            $builder = new Builder($config['build'], $this->output);
            try {
                $builder->build();
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                return 1;
            }
        } else {
            $this->warn('Skipping build in dry-run mode.');
        }

        // 2. Scan
        $this->info('Scanning files...');
        $scanner = new FileScanner(base_path(), $config['ignore']);
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
        $manifest = new Manifest(base_path());
        $lastManifestData = $this->option('force') ? [] : $manifest->load();
        
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
        $uploader = $this->getUploader($config);
        
        try {
            $uploader->connect();
            
            if (!empty($toUpload)) {
                $this->info('Uploading files...');
                $uploader->upload($toUpload);
            }

            if (!empty($toDelete)) {
                $this->info('Deleting removed files...');
                $uploader->delete($toDelete);
            }

            $uploader->disconnect();
            
            // 5. Save Manifest
            $this->info('Updating manifest...');
            $manifest->save($allFiles);

            $duration = round(microtime(true) - $startTime, 2);
            $this->info("Deployment finished successfully in {$duration} seconds!");

        } catch (\Exception $e) {
            $this->error("Deployment failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function getUploader(array $config): UploaderInterface
    {
        $driver = $config['driver'];

        if ($driver === 'ftp') {
            return new FtpUploader($config['ftp'], base_path(), $this->output);
        }

        if ($driver === 'sftp') {
            return new SftpUploader($config['sftp'], base_path(), $this->output);
        }

        throw new \InvalidArgumentException("Unsupported driver: {$driver}");
    }
}
