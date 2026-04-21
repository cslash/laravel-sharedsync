<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Cslash\SharedSync\Services\FileScanner;
use Cslash\SharedSync\Services\Manifest;

class DiffCommand extends Command
{
    protected $signature = 'sharedsync:diff {--all : List all files with status} {--limit=100 : Paginate output (number of files per page)}';

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
        
        $this->info('Deployment Diff:');
        $this->line(str_repeat('-', 40));

        $filesToShow = [];
        $newCount = 0;
        $updateCount = 0;
        $unchangedCount = 0;

        foreach ($allFiles as $file) {
            $path = $file['path'];
            $status = ' ';
            
            if (in_array($path, $toUploadPaths)) {
                if (isset($lastManifestData[$path])) {
                    $status = 'U'; // Update
                    $updateCount++;
                } else {
                    $status = 'N'; // New
                    $newCount++;
                }
            } else {
                $unchangedCount++;
            }
            
            if ($status !== ' ' || $this->option('all')) {
                $filesToShow[] = sprintf('%s %s', $status, $path);
            }
        }

        $limit = $this->option('limit');

        if ($limit && count($filesToShow) > $limit) {
            $chunks = array_chunk($filesToShow, (int) $limit);
            foreach ($chunks as $index => $chunk) {
                foreach ($chunk as $line) {
                    $this->line($line);
                }

                if ($index < count($chunks) - 1) {
                    if (!$this->confirm('Show more?', true)) {
                        break;
                    }
                }
            }
        } else {
            foreach ($filesToShow as $line) {
                $this->line($line);
            }
        }

        $this->line(str_repeat('-', 40));
        $this->info(sprintf('Summary: %d new, %d to update, %d unchanged.', 
            $newCount,
            $updateCount,
            $unchangedCount
        ));

        return 0;
    }
}
