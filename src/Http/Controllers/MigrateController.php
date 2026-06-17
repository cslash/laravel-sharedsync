<?php

namespace Cslash\SharedSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;

class MigrateController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! $request->hasValidSignature()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired signature.'
            ], 401);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            
            return response()->json([
                'status' => 'success',
                'output' => Artisan::output(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
