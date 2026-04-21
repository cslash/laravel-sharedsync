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

        // Check storage directory
        $storagePath = storage_path();
        if (!File::exists($storagePath)) {
            $errors[] = "Storage directory does not exist: $storagePath";
        } elseif (!is_writable($storagePath)) {
            $errors[] = "Storage directory is not writable: $storagePath";
        } else {
            $checks['storage'] = 'OK';
        }

        // Check bootstrap/cache directory
        $cachePath = base_path('bootstrap/cache');
        if (!File::exists($cachePath)) {
            $errors[] = "Bootstrap cache directory does not exist: $cachePath";
        } elseif (!is_writable($cachePath)) {
            $errors[] = "Bootstrap cache directory is not writable: $cachePath";
        } else {
            $checks['bootstrap_cache'] = 'OK';
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
