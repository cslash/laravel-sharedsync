<?php

namespace Cslash\SharedSync\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\OutputInterface;

class Builder
{
    protected $output;
    protected $config;
    protected $basePath;
    protected $buildPath;

    public function __construct(array $config, string $basePath, OutputInterface $output)
    {
        $this->config = $config;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->output = $output;
    }

    /**
     * @return string The build directory path
     */
    public function build(): string
    {
        $this->buildPath = $this->createTempDirectory();

        $this->copyProjectToTemp();

        $this->runStep(['composer', 'install', '--no-dev', '--optimize-autoloader'], 'Installing Composer dependencies...');

        if ($this->config['npm'] ?? false) {
            $this->runStep(['npm', 'i'], "Installing NPM dependencies (npm i)...");
            $this->runStep(['npm', 'run', 'build'], 'Building assets...');
        }

        if ($this->config['artisan_cache'] ?? false) {
            $this->runStep(['php', 'artisan', 'config:cache'], 'Caching configuration...');
            $this->runStep(['php', 'artisan', 'route:cache'], 'Caching routes...');
            $this->runStep(['php', 'artisan', 'view:cache'], 'Caching views...');
        }

        return $this->buildPath;
    }

    protected function createTempDirectory(): string
    {
        $tempBase = $this->config['temp_path'] ?? sys_get_temp_dir();
        $path = rtrim($tempBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sharedsync-build-' . uniqid();

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new \RuntimeException("Could not create temporary directory: {$path}");
        }

        return $path;
    }

    protected function copyProjectToTemp(): void
    {
        $this->output->writeln("<info>Copying project to temporary directory...</info>");

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $excludes = ['.git', 'vendor', 'node_modules'];

        foreach ($iterator as $item) {
            $relativePath = $this->getRelativePath($item->getPathname());

            // Check if it should be excluded
            foreach ($excludes as $exclude) {
                if ($relativePath === $exclude || str_starts_with($relativePath, $exclude . DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }

            $targetPath = $this->buildPath . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0777, true);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    protected function getRelativePath(string $fullPath): string
    {
        $path = str_replace($this->basePath, '', $fullPath);
        return ltrim($path, DIRECTORY_SEPARATOR);
    }

    public function cleanup(): void
    {
        if ($this->buildPath && is_dir($this->buildPath)) {
            $this->output->writeln("<info>Cleaning up build directory...</info>");
            $this->deleteDirectory($this->buildPath);
        }
    }

    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;

            if (!is_link($path) && is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }

        }

        rmdir($dir);
    }

    protected function runStep(array $command, string $message): void
    {
        $this->output->writeln("<info>{$message}</info>");

        $process = new Process($command);
        $process->setWorkingDirectory($this->buildPath);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "Command failed with exit code %d: %s\nError: %s",
                $process->getExitCode(),
                implode(' ', $command),
                $process->getErrorOutput()
            ));
        }
    }
}
