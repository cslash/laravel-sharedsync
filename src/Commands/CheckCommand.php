<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;

class CheckCommand extends Command
{
    use InteractsWithUploader, RunsRemoteChecks;

    protected $signature = 'sharedsync:check';

    protected $description = 'Run remote health checks on the deployed project';

    public function handle()
    {
        $config = config('sharedsync');

        if (empty($config)) {
            $this->error('SharedSync configuration not found. Please run: php artisan vendor:publish --tag=sharedsync-config');
            return 1;
        }

        try {
            $uploader = $this->getUploader($config);
            $uploader->connect();

            $success = $this->runRemoteChecks($config, $uploader);

            $uploader->disconnect();

            return $success ? 0 : 1;
        } catch (\Exception $e) {
            $this->error("Failed to run checks: " . $e->getMessage());
            return 1;
        }
    }
}
