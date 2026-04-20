<?php

namespace Cslash\SharedSync\Commands;

use Cslash\SharedSync\Services\Uploader\FtpUploader;
use Cslash\SharedSync\Services\Uploader\SftpUploader;
use Cslash\SharedSync\Services\Uploader\UploaderInterface;

trait InteractsWithUploader
{
    protected function getUploader(array $config): UploaderInterface
    {
        if ($this->laravel->bound('sharedsync.uploader')) {
            return $this->laravel->make('sharedsync.uploader', [
                'config' => $config[$config['driver']] ?? [],
                'basePath' => base_path(),
                'output' => $this->output
            ]);
        }

        $driver = $config['driver'];
        $driverConfig = $config[$driver] ?? [];
        $basePath = base_path();

        if ($driver === 'ftp') {
            return new FtpUploader($driverConfig, $basePath, $this->output);
        }

        if ($driver === 'sftp') {
            return new SftpUploader($driverConfig, $basePath, $this->output);
        }

        throw new \InvalidArgumentException("Unsupported driver: {$driver}");
    }
}
