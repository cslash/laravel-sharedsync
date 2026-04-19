<?php

namespace Cslash\SharedSync\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\OutputInterface;

class Builder
{
    protected $output;
    protected $config;

    public function __construct(array $config, OutputInterface $output)
    {
        $this->config = $config;
        $this->output = $output;
    }

    public function build(): void
    {
        if ($this->config['composer'] ?? false) {
            $this->output->writeln('<comment>Warning: Running "composer install --no-dev" might cause the current Artisan process to fail if it tries to load dev-dependencies after they are removed.</comment>');
            $this->runStep(['composer', 'install', '--no-dev', '--optimize-autoloader'], 'Installing Composer dependencies...');
        }

        if ($this->config['npm'] ?? false) {
            $this->runStep(['npm', 'install'], 'Installing NPM dependencies...');
            $this->runStep(['npm', 'run', 'build'], 'Building assets...');
        }

        if ($this->config['artisan_cache'] ?? false) {
            $this->runStep(['php', 'artisan', 'config:cache'], 'Caching configuration...');
            $this->runStep(['php', 'artisan', 'route:cache'], 'Caching routes...');
            $this->runStep(['php', 'artisan', 'view:cache'], 'Caching views...');
        }
    }

    protected function runStep(array $command, string $message): void
    {
        $this->output->writeln("<info>{$message}</info>");

        $process = new Process($command);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) {
            if ($this->output->isVerbose()) {
                $this->output->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Command failed: " . implode(' ', $command) . "\nError: " . $process->getErrorOutput());
        }
    }
}
