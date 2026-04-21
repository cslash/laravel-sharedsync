<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Cslash\SharedSync\Services\Builder;
use Cslash\SharedSync\Services\FileScanner;
use Cslash\SharedSync\Services\Manifest;
use Cslash\SharedSync\Services\Uploader\UploaderInterface;

class DeployCommand extends Command
{
    use InteractsWithUploader;

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

        $buildPath = base_path();
        $builder = null;

        try {
            // 1. Build
            if (!$this->option('dry-run')) {
                $builder = new Builder($config['build'], base_path(), $this->output);
                $buildPath = $builder->build();
            } else {
                $this->warn('Skipping build in dry-run mode.');
            }

            // 2. Scan
            $this->info('Scanning files...');
            $scanner = new FileScanner($buildPath, $config['ignore']);
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
            $uploader = $this->getUploader($config, $buildPath);
            $uploader->connect();

            if (!empty($toUpload)) {
                $this->info('Uploading files...');
                $uploader->upload($toUpload);
            }

            if (!empty($toDelete)) {
                $this->info('Deleting removed files...');
                $uploader->delete($toDelete);
            }

            // 5. Save Manifest
            $this->info('Updating manifest...');
            $manifest->save($allFiles);

            // 6. Remote Checks
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

    protected function runRemoteChecks(array $config, UploaderInterface $uploader): void
    {
        if (empty($config['url'])) {
            $this->warn('No deployment URL configured. Skipping remote checks.');
            return;
        }

        $this->info('Running remote checks...');

        $token = Str::random(32);
        $tokenFile = '.sharedsync-token';

        try {
            // Upload token
            $uploader->put($tokenFile, $token);

            // Call endpoint
            $url = rtrim($config['url'], '/') . '/sharedsync';
            $response = Http::withHeaders([
                'X-SharedSync-Token' => $token,
            ])->post($url);

            if ($response->failed()) {
                $this->error('Remote checks failed!');
                $data = $response->json();
                if (isset($data['errors'])) {
                    foreach ($data['errors'] as $error) {
                        $this->error("- $error");
                    }
                } else {
                    $this->error($response->body());
                }
            } else {
                $this->info('Remote checks passed successfully.');
                $data = $response->json();
                if (isset($data['checks'])) {
                    foreach ($data['checks'] as $check => $status) {
                        $this->line("- $check: <info>$status</info>");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error('Failed to run remote checks: ' . $e->getMessage());
        } finally {
            // Delete token
            try {
                $uploader->delete([$tokenFile]);
            } catch (\Exception $e) {
                $this->warn('Failed to delete remote token file.');
            }
        }
    }
}
