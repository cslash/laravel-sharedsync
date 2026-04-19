<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Cslash\SharedSync\Services\FileScanner;
use Cslash\SharedSync\Services\Manifest;

class DiffCommand extends Command
{
    protected $signature = 'sharedsync:diff';

    protected $description = 'List files to be updated or created on the remote server';

    public function handle()
    {
        $config = config('sharedsync');

        if (empty($config)) {
            $this->error('SharedSync configuration not found. Please run: php artisan vendor:publish --tag=sharedsync-config');
            return 1;
        }

        $this->info('Scanning files and comparing with manifest...');

        // 1. Scan
        $scanner = new FileScanner(base_path(), $config['ignore']);
        $allFiles = $scanner->scan();

        // 2. Manifest Comparison
        $manifest = new Manifest(base_path());
        $lastManifestData = $manifest->load();
        
        $diff = $manifest->compare($allFiles, $lastManifestData);
        
        $toUpload = $diff['upload'];
        $toUploadPaths = array_column($toUpload, 'path');
        
        $lastManifestPaths = array_keys($lastManifestData);

        $this->info('Deployment Diff:');
        $this->line(str_repeat('-', 40));

        foreach ($allFiles as $file) {
            $path = $file['path'];
            $status = ' ';
            
            if (in_array($path, $toUploadPaths)) {
                if (isset($lastManifestData[$path])) {
                    $status = 'U'; // Update
                } else {
                    $status = 'N'; // New
                }
            }
            
            $this->line(sprintf('%s %s', $status, $path));
        }

        $this->line(str_repeat('-', 40));
        $this->info(sprintf('Summary: %d new, %d to update, %d unchanged.', 
            count(array_filter($toUpload, function($f) use ($lastManifestData) { return !isset($lastManifestData[$f['path']]); })),
            count(array_filter($toUpload, function($f) use ($lastManifestData) { return isset($lastManifestData[$f['path']]); })),
            count($allFiles) - count($toUpload)
        ));

        return 0;
    }
}
