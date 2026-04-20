<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;

class LsCommand extends Command
{
    use InteractsWithUploader;

    protected $signature = 'sharedsync:ls {path? : The remote path to list}';

    protected $description = 'List files on the remote server';

    public function handle()
    {
        $config = config('sharedsync');

        if (empty($config)) {
            $this->error('SharedSync configuration not found. Please run: php artisan vendor:publish --tag=sharedsync-config');
            return 1;
        }

        $path = $this->argument('path') ?: '.';
        $driver = $config['driver'];

        $this->info("Listing files in '{$path}' via {$driver}...");

        try {
            $uploader = $this->getUploader($config);
            $uploader->connect();

            $files = $uploader->list($path);

            if (empty($files)) {
                $this->info("No files found or directory is empty.");
            } else {
                foreach ($files as $file) {
                    $this->line($file);
                }
            }

            $uploader->disconnect();

            return 0;
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return 1;
        }
    }
}
