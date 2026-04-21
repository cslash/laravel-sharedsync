<?php

namespace Cslash\SharedSync\Services\Uploader;

use Orchestra\Canvas\Presets\Package;
use Symfony\Component\Console\Output\OutputInterface;

class FtpUploader implements UploaderInterface
{
    protected $config;
    protected $output;
    protected $connection;
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
        $port = $this->config['port'] ?? 21;
        $ssl = $this->config['ssl'] ?? false;
        $timeout = $this->config['timeout'] ?? 90;

        $this->output->writeln("<info>Connecting to FTP: {$host}:{$port}</info>");

        $this->connection = $ssl ? @ftp_ssl_connect($host, $port, $timeout) : @ftp_connect($host, $port, $timeout);

        if (!$this->connection) {
            throw new \RuntimeException("Could not connect to FTP host: {$host}");
        }

        if (!@ftp_login($this->connection, $this->config['username'], $this->config['password'])) {
            throw new \RuntimeException("Could not login to FTP with provided credentials.");
        }

        if ($this->config['passive'] ?? true) {
            ftp_pasv($this->connection, true);
        }

        // check if root exists, if not tries to create it
        $this->remoteRoot = rtrim($this->config['root'] ?? '/', '/') . '/';
        $this->mkdir($this->remoteRoot);
        $this->chdir($this->remoteRoot);
    }

    public function upload(array $files): void
    {
        foreach ($files as $file) {
            $relativePath = ltrim($file['path'], '/');
            $localPath = $this->basePath . DIRECTORY_SEPARATOR . $relativePath;
            $remotePath = $this->remoteRoot . $relativePath;

            $this->output->writeln("Uploading: {$relativePath}", OutputInterface::VERBOSITY_VERBOSE);

            $this->mkdir(dirname($remotePath));

            $this->retry(function () use ($remotePath, $localPath) {
                return ftp_put($this->connection, $remotePath, $localPath, FTP_BINARY);
            }, "Failed to upload: {$relativePath}");
        }
    }

    public function put(string $remotePath, string $content): void
    {
        $temp = tmpfile();
        fwrite($temp, $content);
        fseek($temp, 0);

        $this->mkdir(dirname($remotePath));

        $this->retry(function () use ($remotePath, $temp) {
            return ftp_fput($this->connection, $remotePath, $temp, FTP_BINARY);
        }, "Failed to put content to: {$remotePath}");

        fclose($temp);
    }

    public function delete(array $files): void
    {
        foreach ($files as $remotePath) {
            $this->output->writeln("Deleting: {$remotePath}", OutputInterface::VERBOSITY_VERBOSE);
            @ftp_delete($this->connection, $remotePath);
        }
    }

    public function list(string $path): array
    {
        $list = ftp_nlist($this->connection, $path ?: '.');

        if ($list === false) {
            return [];
        }

        return $list;
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            ftp_close($this->connection);
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

        $current = @ftp_pwd($this->connection);

        if (!@ftp_chdir($this->connection, $path)) {
            return false;
        }
        
        @ftp_chdir($this->connection, $current);

        $this->dirCache[$path] = true;

        return true;
    }

    public function chdir(string $path): void
    {
        if (!@ftp_chdir($this->connection, $path)) {
            throw new \RuntimeException("Could not change directory to: {$path}");
        }
    }

    /**
     * @param string $path
     * @return void
     *
     * Ensure directories in path exist on FTP server,
     * if not create them recursively.
     */
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
                if (!@ftp_mkdir($this->connection, $current)) {
                    throw new \RuntimeException("Could not create directory: {$current}");
                }
                $this->dirCache[$current] = true;
            }

        }
    }

    protected function retry(callable $callback, string $errorMessage, int $attempts = 3): void
    {
        for ($i = 0; $i < $attempts; $i++) {
            if ($callback()) {
                return;
            }
            usleep(500000); // 0.5s
        }

        throw new \RuntimeException($errorMessage);
    }
}
