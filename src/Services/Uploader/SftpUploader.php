<?php

namespace Cslash\SharedSync\Services\Uploader;

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;
use Symfony\Component\Console\Output\OutputInterface;

class SftpUploader implements UploaderInterface
{
    protected $config;
    protected $output;
    protected $sftp;
    protected $basePath;
    protected $remoteRoot;
    protected array $dirCache = [];

    public function __construct(array $config, string $basePath, OutputInterface $output)
    {
        $this->config = $config;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->output = $output;
    }

    public function connect(): void
    {
        $host = $this->config['host'];
        $port = $this->config['port'] ?? 22;
        $timeout = $this->config['timeout'] ?? 90;

        $this->output->writeln("<info>Connecting to SFTP: {$host}:{$port}</info>");

        if (!$this->sftp) {
            $this->sftp = new SFTP($host, $port, $timeout);
        }

        if (!empty($this->config['privateKey'])) {
            $key = PublicKeyLoader::load($this->config['privateKey']);
            if (!$this->sftp->login($this->config['username'], $key)) {
                throw new \RuntimeException("SFTP Login failed (SSH Key)");
            }
        } else {
            if (!$this->sftp->login($this->config['username'], $this->config['password'])) {
                throw new \RuntimeException("SFTP Login failed (Password)");
            }
        }

        $this->remoteRoot = rtrim($this->config['root'] ?? '/', '/') . '/';
        $this->mkdir($this->remoteRoot);
        if (!$this->sftp->chdir($this->remoteRoot)) {
            throw new \RuntimeException("Could not change SFTP directory to root: {$this->remoteRoot}");
        }
    }

    public function upload(array $files): void
    {
        foreach ($files as $file) {
            $relativePath = ltrim($file['path'], '/');
            $localPath = $this->basePath . DIRECTORY_SEPARATOR . $relativePath;
            $remotePath = $this->remoteRoot . $relativePath;

            $this->output->writeln("Uploading: {$relativePath}", OutputInterface::VERBOSITY_VERBOSE);

            $this->mkdir(dirname($remotePath));

            if (!$this->sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
                throw new \RuntimeException("Failed to upload via SFTP: {$relativePath}");
            }
        }
    }

    public function put(string $remotePath, string $content): void
    {
        $this->mkdir(dirname($remotePath));

        if (!$this->sftp->put($remotePath, $content)) {
            throw new \RuntimeException("Failed to put content via SFTP to: {$remotePath}");
        }
    }

    public function delete(array $files): void
    {
        foreach ($files as $remotePath) {
            $this->output->writeln("Deleting: {$remotePath}", OutputInterface::VERBOSITY_VERBOSE);
            $this->sftp->delete($remotePath);
        }
    }

    public function list(string $path): array
    {
        return $this->sftp->nlist($path ?: '.');
    }

    public function disconnect(): void
    {
        if ($this->sftp) {
            $this->sftp->disconnect();
        }
    }

    public function is_dir(string $path): bool
    {
        if ($path === '.' || $path === '/' || empty($path)) {
            return true;
        }

        if (isset($this->dirCache[$path])) {
            return true;
        }

        if (!$this->sftp->is_dir($path)) {
            return false;
        }

        $this->dirCache[$path] = true;

        return true;
    }

    public function chdir(string $path): void
    {
        if (!$this->sftp->chdir($path)) {
            throw new \RuntimeException("Could not change SFTP directory to: {$path}");
        }
    }

    public function mkdir(string $path): void
    {
        if ($path === '.' || $path === '/' || empty($path)) {
            return;
        }

        $parts = explode('/', str_replace('\\', '/', $path));
        $current = '';
        foreach ($parts as $part) {
            if (empty($part) && $current === '') {
                $current = '/';
                continue;
            }
            $current .= ($current === '/' ? '' : '/') . $part;
            if (!$this->is_dir($current)) {
                $this->output->writeln("Creating directory: {$current}", OutputInterface::VERBOSITY_VERBOSE);
                $this->sftp->mkdir($current);
                $this->dirCache[$current] = true;
            }
        }
    }
}
