<?php

namespace Cslash\SharedSync\Commands;

use Cslash\SharedSync\Services\Uploader\UploaderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait RunsRemoteChecks
{
    /**
     * Steps executed remotely, in order. Each is dispatched as a separate
     * request to the SharedSync endpoint so that we can report success or
     * failure independently for each one.
     */
    protected array $remoteSteps = ['directories', 'symlink', 'cache'];

    protected function runRemoteChecks(array $config, UploaderInterface $uploader): bool
    {
        if (empty($config['url'])) {
            $this->warn('No deployment URL configured. Skipping remote checks.');
            return true;
        }

        $this->info('Running remote checks...');

        $token = Str::random(32);
        $tokenFile = '.sharedsync-token';
        $url = rtrim($config['url'], '/') . '/sharedsync';

        $overallSuccess = true;
        $aggregatedChecks = [];

        try {
            // Upload token once for all step calls.
            $uploader->put($tokenFile, $token);

            foreach ($this->remoteSteps as $step) {
                [$ok, $stepChecks] = $this->runRemoteStep($url, $token, $step);
                if (!$ok) {
                    $overallSuccess = false;
                }
                $aggregatedChecks = array_merge($aggregatedChecks, $stepChecks);
            }

            if ($overallSuccess) {
                $this->info('Remote checks passed successfully.');
            } else {
                $this->error('Some remote checks failed.');
            }

            foreach ($aggregatedChecks as $check => $status) {
                $this->line("- $check: <info>$status</info>");
            }
        } catch (\Exception $e) {
            $this->error('Failed to run remote checks: ' . $e->getMessage());
            $overallSuccess = false;
        } finally {
            try {
                $uploader->delete([$tokenFile]);
            } catch (\Exception $e) {
                $this->warn('Failed to delete remote token file.');
            }
        }

        return $overallSuccess;
    }

    /**
     * Call a single remote step and report its outcome.
     *
     * @return array{0: bool, 1: array<string, string>}
     */
    protected function runRemoteStep(string $url, string $token, string $step): array
    {
        try {
            $response = Http::withHeaders([
                'X-SharedSync-Token' => $token,
            ])->post($url, ['step' => $step]);
        } catch (\Exception $e) {
            $this->error("Step '{$step}' failed: " . $e->getMessage());
            return [false, []];
        }

        $data = $response->json();
        $checks = is_array($data) && isset($data['checks']) ? $data['checks'] : [];

        if ($response->failed()) {
            $this->error("Step '{$step}' failed.");
            if (is_array($data) && !empty($data['errors'])) {
                foreach ($data['errors'] as $error) {
                    $this->error("- {$error}");
                }
            } else {
                $this->error($response->body());
            }
            return [false, $checks];
        }

        $this->line("Step '{$step}' completed.");
        return [true, $checks];
    }
}
