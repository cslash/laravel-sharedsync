<?php

namespace Cslash\SharedSync\Tests;

use Cslash\SharedSync\Http\Controllers\VendorController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use ZipArchive;

class VendorControllerTest extends TestCase
{
    public function test_vendor_controller_extracts_zip()
    {
        $zipName = 'test-vendor.zip';
        $zipPath = base_path($zipName);
        
        // Create a dummy vendor directory and zip it
        $vendorDir = base_path('vendor-test');
        if (!File::exists($vendorDir)) {
            mkdir($vendorDir);
        }
        file_put_contents($vendorDir . '/test.txt', 'vendor content');
        
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($vendorDir . '/test.txt', 'vendor/test.txt');
        $zip->close();
        
        try {
            $controller = new VendorController();
            $request = Request::create('/sharedsync/vendor', 'GET', ['zip' => $zipName]);
            
            $response = $controller($request);
            
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertFileExists(base_path('vendor/test.txt'));
            $this->assertEquals('vendor content', file_get_contents(base_path('vendor/test.txt')));
            $this->assertFileDoesNotExist($zipPath);
        } finally {
            if (File::exists($zipPath)) {
                File::delete($zipPath);
            }
            if (File::exists($vendorDir)) {
                File::deleteDirectory($vendorDir);
            }
            if (File::exists(base_path('vendor/test.txt'))) {
                File::delete(base_path('vendor/test.txt'));
            }
        }
    }

    public function test_vendor_controller_returns_404_if_zip_missing()
    {
        $controller = new VendorController();
        $request = Request::create('/sharedsync/vendor', 'GET', ['zip' => 'non-existent.zip']);
        
        $response = $controller($request);
        
        $this->assertEquals(404, $response->getStatusCode());
    }
}
