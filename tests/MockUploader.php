<?php

namespace Cslash\SharedSync\Tests;

use Cslash\SharedSync\Services\Uploader\UploaderInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MockUploader implements UploaderInterface
{
    public array $uploadedFiles = [];
    public array $deletedFiles = [];
    public array $createdDirectories = [];
    public array $existingDirectories = ['/'];
    public bool $connected = false;

    public function connect(): void
    {
        $this->connected = true;
    }

    public function upload(array $files): void
    {
        if (empty($files)) {
            // Trick used in DeployCommand to trigger root directory creation
            return;
        }

        foreach ($files as $file) {
            $this->uploadedFiles[] = $file['path'];
            $dir = dirname($file['path']);
            if ($dir !== '.' && !in_array($dir, $this->createdDirectories)) {
                $this->createdDirectories[] = $dir;
            }
        }
    }

    public function delete(array $files): void
    {
        foreach ($files as $file) {
            $this->deletedFiles[] = $file;
        }
    }

    public function list(string $path): array
    {
        return [];
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function is_dir(string $path): bool
    {
        return in_array($path, $this->existingDirectories) || in_array($path, $this->createdDirectories);
    }

}
