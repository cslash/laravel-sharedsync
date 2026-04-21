<?php

namespace Cslash\SharedSync\Commands;

use Cslash\SharedSync\Services\Uploader\UploaderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait RunsRemoteChecks
{
    protected function runRemoteChecks(array $config, UploaderInterface $uploader): bool
    {
        if (empty($config['url'])) {
            $this->warn('No deployment URL configured. Skipping remote checks.');
            return true;
        }

        $this->info('Running remote checks...');

        $token = Str::random(32);
        $tokenFile = '.sharedsync-token';
        $success = true;

        try {
            // Upload token
            $uploader->put($tokenFile, $token);

            // Call endpoint
            $url = rtrim($config['url'], '/') . '/sharedsync';
            $response = Http::withHeaders([
                'X-SharedSync-Token' => $token,
            ])->post($url);

            if ($response->failed()) {
                $this->error('Remote checks failed!');
                $success = false;
                $data = $response->json();
                if (isset($data['errors'])) {
                    foreach ($data['errors'] as $error) {
                        $this->error("- $error");
                    }
                } else {
                    $this->error($response->body());
                }
            } else {
                $this->info('Remote checks passed successfully.');
                $data = $response->json();
                if (isset($data['checks'])) {
                    foreach ($data['checks'] as $check => $status) {
                        $this->line("- $check: <info>$status</info>");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error('Failed to run remote checks: ' . $e->getMessage());
            $success = false;
        } finally {
            // Delete token
            try {
                $uploader->delete([$tokenFile]);
            } catch (\Exception $e) {
                $this->warn('Failed to delete remote token file.');
            }
        }

        return $success;
    }
}
