<?php
 
namespace Cslash\SharedSync\Http\Controllers;
 
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use ZipArchive;
 
class VendorController extends Controller
{
    /**
     * Extract the uploaded vendor zip file.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        try {
            $zipFile = $request->input('zip');

            if (!$zipFile) {
                return response()->json(['error' => 'No zip file specified'], 400);
            }

            $zipPath = base_path($zipFile);

            if (!File::exists($zipPath)) {
                return response()->json(['error' => "Zip file not found: {$zipFile}"], 404);
            }

            if (!class_exists('ZipArchive')) {
                return response()->json(['error' => 'ZipArchive extension not found on server'], 500);
            }

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return response()->json(['error' => 'Could not open zip file'], 500);
            }

            // Before extracting, we might want to ensure the vendor directory is clean or exists.
            // Actually, ZipArchive::extractTo will overwrite existing files but might not remove old ones.
            // For a clean sync, it might be better to remove the existing vendor directory, 
            // but that's risky if the extraction fails.

            if (!$zip->extractTo(base_path())) {
                $zip->close();
                return response()->json(['error' => 'Failed to extract zip file'], 500);
            }

            $zip->close();

            // Clean up the zip file after extraction
            File::delete($zipPath);

            return response()->json([
                'status' => 'success',
                'message' => 'Vendor directory extracted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'error' => 'Remote extraction failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
