<?php

namespace Cslash\SharedSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;

class SharedSyncController extends Controller
{
    public function __invoke(Request $request)
    {
        $tokenFile = base_path('.sharedsync-token');
        $token = File::exists($tokenFile) ? trim(File::get($tokenFile)) : null;

        if (!$token || $request->header('X-SharedSync-Token') !== $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $checks = [];
        $errors = [];

        // Check and create required directories
        $directories = [
            'bootstrap/cache' => base_path('bootstrap/cache'),
            'storage/app/public' => storage_path('app/public'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
        ];

        foreach ($directories as $label => $path) {
            if (!File::exists($path)) {
                try {
                    File::makeDirectory($path, 0775, true);
                    $checks[$label] = 'Created';
                } catch (\Exception $e) {
                    $errors[] = "Failed to create directory: $path. " . $e->getMessage();
                    continue;
                }
            }

            if (!is_writable($path)) {
                $errors[] = "Directory is not writable: $path";
            } else {
                $checks[$label] = $checks[$label] ?? 'OK';
            }
        }

        // Check public/storage symlink
        $publicStoragePath = public_path('storage');
        if (!File::exists($publicStoragePath)) {
            try {
                Artisan::call('storage:link');
                $checks['public_storage_symlink'] = 'Created';
            } catch (\Exception $e) {
                $errors[] = "Failed to create public/storage symlink: " . $e->getMessage();
            }
        } else {
            $checks['public_storage_symlink'] = 'OK';
        }

        // Artisan caching
        if (config('sharedsync.build.artisan_cache')) {
            foreach (['config', 'route', 'view'] as $type) {
                try {
                    Artisan::call("$type:cache");
                    $checks["{$type}_cache"] = 'OK';
                } catch (\Exception $e) {
                    $errors[] = "Failed to cache $type: " . $e->getMessage();
                }
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'status' => 'error',
                'checks' => $checks,
                'errors' => $errors,
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'checks' => $checks,
        ]);
    }
}
