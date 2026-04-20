<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;

class TestConnectionCommand extends Command
{
    use InteractsWithUploader;

    protected $signature = 'sharedsync:test';

    protected $description = 'Test the connection to the remote server';

    public function handle()
    {
        $config = config('sharedsync');

        if (empty($config)) {
            $this->error('SharedSync configuration not found. Please run: php artisan vendor:publish --tag=sharedsync-config');
            return 1;
        }

        $driver = $config['driver'];
        $this->displayConfig($driver, $config[$driver]);

        $this->info("Testing {$driver} connection...");

        try {
            $uploader = $this->getUploader($config);
            
            $startTime = microtime(true);
            $uploader->connect();
            $duration = round(microtime(true) - $startTime, 2);
            
            $this->info("Successfully connected to the remote server in {$duration} seconds!");
            
            $driverConfig = $config[$driver];
            $remoteRoot = $driverConfig['root'] ?? '/';
            
            if ($uploader->is_dir($remoteRoot)) {
                $this->info("Remote directory exists: {$remoteRoot}");
            } else {
                $this->warn("Remote directory does not exist: {$remoteRoot}");
                $this->info("It will be created during the first deployment.");
            }
            
            $uploader->disconnect();
            
            return 0;
        } catch (\Exception $e) {
            $this->error("Connection failed: " . $e->getMessage());
            return 1;
        }
    }

    protected function displayConfig(string $driver, array $config): void
    {
        $this->info("Configuration for {$driver}:");
        
        $tableData = [];
        foreach ($config as $key => $value) {
            if ($key === 'password' || $key === 'privateKey') {
                $value = '********';
            }
            
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            
            $tableData[] = [$key, $value];
        }

        $this->table(['Parameter', 'Value'], $tableData);
    }
}
