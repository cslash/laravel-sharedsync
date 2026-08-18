<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Cslash\SharedSync\Services\VendorManager;
use Cslash\SharedSync\Services\Uploader\UploaderInterface;

class VendorCommand extends Command
{
    use InteractsWithUploader;

    protected $signature = 'sharedsync:vendor {--deploy : Deploy the vendor directory}';

    protected $description = 'Manage and deploy the vendor directory';

    public function handle()
    {
        $config = config('sharedsync');

        if (empty($config)) {
            $this->error('SharedSync configuration not found. Please run: php artisan vendor:publish --tag=sharedsync-config');
            return 1;
        }

        $uploader = $this->getUploader($config);
        $baseUrl = $config['url'] ?? '';
        $vendorManager = new VendorManager($uploader, $baseUrl, $this->output);

        if ($this->option('deploy')) {
            $this->info('Starting vendor deployment...');
            
            $token = \Illuminate\Support\Str::random(48);
            
            // Create the remote token file
            $uploader->connect();
            $uploader->put('.sharedsync-token', $token);
            $uploader->disconnect();
            
            $vendorManager->setToken($token);

            try {
                if ($vendorManager->deployVendor(base_path('vendor'))) {
                    $this->info('Vendor deployed successfully.');
                    return 0;
                }
            } finally {
                // Cleanup: remove the remote token file
                try {
                    $uploader->connect();
                    $uploader->delete(['.sharedsync-token']);
                    $uploader->disconnect();
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            $this->error('Vendor deployment failed.');
            return 1;
        }

        $this->info('Installed Packages:');
        $packages = $vendorManager->getInstalledPackages(base_path());
        
        if (empty($packages)) {
            $this->warn('No packages found or composer.lock missing.');
        } else {
            foreach ($packages as $package => $version) {
                $this->line("- <info>{$package}</info>: {$version}");
            }
        }

        return 0;
    }
}
