<?php

namespace Cslash\SharedSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class SharedSyncController extends Controller
{
    /**
     * Available steps. Each step is mapped to a dedicated method that
     * returns an array with 'checks' and 'errors' keys.
     */
    protected array $steps = [
        'directories' => 'runDirectories',
        'symlink'     => 'runSymlink',
        'cache'       => 'runCache',
    ];

    public function __invoke(Request $request)
    {
        $tokenFile = base_path('.sharedsync-token');
        $token = File::exists($tokenFile) ? trim(File::get($tokenFile)) : null;

        if (!$token || $request->header('X-SharedSync-Token') !== $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $requestedStep = $request->input('step', 'all');

        $checks = [];
        $errors = [];

        if ($requestedStep === 'all') {
            foreach (array_keys($this->steps) as $stepName) {
                $result = $this->runStep($stepName);
                $checks = array_merge($checks, $result['checks']);
                $errors = array_merge($errors, $result['errors']);
            }
        } else {
            if (!isset($this->steps[$requestedStep])) {
                return response()->json([
                    'status' => 'error',
                    'errors' => ["Unknown step: {$requestedStep}"],
                ], 400);
            }

            $result = $this->runStep($requestedStep);
            $checks = $result['checks'];
            $errors = $result['errors'];
        }

        if (!empty($errors)) {
            return response()->json([
                'status' => 'error',
                'step'   => $requestedStep,
                'checks' => $checks,
                'errors' => $errors,
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'step'   => $requestedStep,
            'checks' => $checks,
        ]);
    }

    protected function runStep(string $stepName): array
    {
        $method = $this->steps[$stepName];
        return $this->{$method}();
    }

    /**
     * Ensure that all required runtime directories exist and are writable.
     */
    protected function runDirectories(): array
    {
        $checks = [];
        $errors = [];

        $directories = [
            'bootstrap/cache'             => base_path('bootstrap/cache'),
            'storage/app/public'          => storage_path('app/public'),
            'storage/framework/cache'     => storage_path('framework/cache'),
            'storage/framework/sessions'  => storage_path('framework/sessions'),
            'storage/framework/views'     => storage_path('framework/views'),
            'storage/logs'                => storage_path('logs'),
        ];

        foreach ($directories as $label => $path) {
            if (!File::exists($path)) {
                try {
                    File::makeDirectory($path, 0775, true);
                    $checks[$label] = 'Created';
                } catch (\Exception $e) {
                    $errors[] = "Failed to create directory: {$path}. " . $e->getMessage();
                    continue;
                }
            }

            if (!is_writable($path)) {
                $errors[] = "Directory is not writable: {$path}";
            } else {
                $checks[$label] = $checks[$label] ?? 'OK';
            }
        }

        return ['checks' => $checks, 'errors' => $errors];
    }

    /**
     * Ensure the public/storage symlink to storage/app/public exists.
     *
     * - If a link or directory already exists at public/storage it's accepted as OK.
     * - When created, the link is verified afterwards because some FTP-based
     *   environments silently fail to create symlinks.
     */
    protected function runSymlink(): array
    {
        $checks = [];
        $errors = [];

        $target = storage_path('app/public');
        $link   = public_path('storage');

        // Already present (either as a symlink or a regular directory): nothing to do.
        if (is_link($link) || file_exists($link)) {
            $checks['public_storage_symlink'] = 'OK';
            return ['checks' => $checks, 'errors' => $errors];
        }

        // If the public/ directory itself is missing there's nothing we can link into.
        $publicDir = dirname($link);
        if (!is_dir($publicDir)) {
            $checks['public_storage_symlink'] = 'Skipped (no public dir)';
            return ['checks' => $checks, 'errors' => $errors];
        }

        try {
            // Use a relative target so the link remains valid if paths move.
            $relativeTarget = '../storage/app/public';
            @symlink($relativeTarget, $link);
        } catch (\Exception $e) {
            $errors[] = 'Failed to create public/storage symlink: ' . $e->getMessage();
            return ['checks' => $checks, 'errors' => $errors];
        }

        // Verify creation: FTP-created links sometimes don't actually appear.
        clearstatcache(true, $link);
        if (is_link($link) || file_exists($link)) {
            $checks['public_storage_symlink'] = 'Created';
        } else {
            $errors[] = "public/storage symlink was not created (target: {$target}).";
        }

        return ['checks' => $checks, 'errors' => $errors];
    }

    /**
     * Run Artisan cache commands when enabled in the configuration.
     */
    protected function runCache(): array
    {
        $checks = [];
        $errors = [];

        if (!config('sharedsync.build.artisan_cache')) {
            return ['checks' => $checks, 'errors' => $errors];
        }

        foreach (['config', 'route', 'view'] as $type) {
            try {
                Artisan::call("{$type}:cache");
                $checks["{$type}_cache"] = 'OK';
            } catch (\Exception $e) {
                $errors[] = "Failed to cache {$type}: " . $e->getMessage();
            }
        }

        return ['checks' => $checks, 'errors' => $errors];
    }
}
