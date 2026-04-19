<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Cslash\SharedSync\Services\Uploader\FtpUploader;
use Cslash\SharedSync\Services\Uploader\SftpUploader;
use Cslash\SharedSync\Services\Uploader\UploaderInterface;

class LsCommand extends Command
{
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
