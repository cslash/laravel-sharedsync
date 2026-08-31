<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Cslash\SharedSync\Services\VendorManager;
use Cslash\SharedSync\Services\Uploader\UploaderInterface;

class VendorCommand extends Command
{
    use InteractsWithUploader;

    protected $signature = 'sharedsync:vendor {action=list : Action to perform (list, diff, install, deploy)}';

    protected $description = 'Manage and deploy the vendor directory';

    public function handle()
    {
        $config = config('sharedsync');

        if (empty($config)) {
            $this->error('SharedSync configuration not found. Please run: php artisan vendor:publish --tag=sharedsync-config');
            return 1;
        }

        $uploader = $this->getUploader($config);
        $vendorManager = new VendorManager($uploader);

        $action = $this->argument('action') ?: 'list';

        switch ($action) {
            case 'list':
                $packages = $vendorManager->list();
                $this->info('Installed Packages:');
                if (empty($packages)) {
                    $this->warn('No packages found or composer.lock missing.');
                } else {
                    foreach ($packages as $package => $version) {
                        $this->line("- <info>{$package}</info>: {$version}");
                    }
                }
                return 0;

            case 'diff':
                $diff = $vendorManager->diff();
                if (empty($diff)) {
                    $this->info('composer.json and composer.lock are in sync.');
                } else {
                    $this->warn('Differences between composer.json and composer.lock:');
                    foreach ($diff as $pkg => $details) {
                        if (is_array($details)) {
                            $this->line("- <comment>{$pkg}</comment>: json ({$details['json']}) vs lock ({$details['lock']})");
                        } else {
                            $this->line("- <comment>{$pkg}</comment>: {$details} (not in lock)");
                        }
                    }
                }
                return 0;

            case 'deploy':
                $this->info('Installing vendor dependencies...');
                $result = $vendorManager->install();
                $this->output->write($result['output']);
                $this->info('Vendor installed in path: ' . $result['path']);
                $this->info('Compressing vendor...');
                $vendorManager->compress();
                $this->info('Uploading vendor...');
                $vendorManager->upload();
                $this->info('Extracting vendor on the remote hosting...');
                $extract_res = $vendorManager->extract();
                $this->output->write(print_r($extract_res, true));
                return 0;

            default:
                $this->error('Invalid action provided. Available actions: list, diff, install, deploy.');
                return 1;
        }
    }
}
