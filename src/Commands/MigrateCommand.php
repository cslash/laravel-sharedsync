<?php

namespace Cslash\SharedSync\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

class MigrateCommand extends Command
{
    protected $signature = 'sharedsync:migrate';

    protected $description = 'Run remote database migrations';

    public function handle()
    {
        $config = config('sharedsync');

        if (empty($config['url'])) {
            $this->error('No deployment URL configured. Please set SHAREDSYNC_URL in your .env file.');
            return 1;
        }

        $this->info('Triggering remote migrations...');

        // Generate signed URL for the remote host
        $originalUrl = config('app.url');
        config(['app.url' => $config['url']]);
        
        $url = URL::temporarySignedRoute('sharedsync.migrate', now()->addMinutes(30));
        
        config(['app.url' => $originalUrl]);

        try {
            $response = Http::get($url);

            if ($response->failed()) {
                $this->error('Remote migration failed!');
                $data = $response->json();
                
                if (isset($data['message'])) {
                    $this->error($data['message']);
                } else {
                    $this->error($response->body());
                }
                
                return 1;
            }

            $this->info('Remote migration successful.');
            $data = $response->json();
            
            if (isset($data['output'])) {
                $this->line($data['output']);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to run remote migration: ' . $e->getMessage());
            return 1;
        }
    }
}
