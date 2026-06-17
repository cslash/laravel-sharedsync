<?php

namespace Cslash\SharedSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ComposerController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! $request->hasValidSignature()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired signature.',
            ], 401);
        }

        try {
            $process = new Process(
                ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction'],
                base_path()
            );
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                return response()->json([
                    'status' => 'error',
                    'output' => $process->getOutput(),
                    'error' => $process->getErrorOutput(),
                ], 500);
            }

            return response()->json([
                'status' => 'success',
                'output' => $process->getOutput(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
