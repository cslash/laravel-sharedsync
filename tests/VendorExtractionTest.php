<?php

namespace Cslash\SharedSync\Tests;

use Cslash\SharedSync\Services\VendorManager;
use Cslash\SharedSync\Tests\MockUploader;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Support\Facades\Http;

class VendorExtractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure stubs directory exists for testing if needed, 
        // but it should already exist in the project.
    }

    public function test_vendor_manager_uploads_stub_and_calls_it()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $baseUrl = 'http://example.com';
        $manager = new VendorManager($uploader, $baseUrl, $output);
        $token = 'test-token-123';
        $manager->setToken($token);

        $vendorDir = base_path('vendor-test');
        if (!is_dir($vendorDir)) {
            mkdir($vendorDir, 0777, true);
        }
        file_put_contents($vendorDir . '/test.txt', 'content');

        Http::fake([
            'http://example.com/sharedsync-vendor-test-token-123*' => Http::response(['status' => 'success', 'message' => 'Vendor updated successfully.'], 200),
        ]);

        $result = $manager->deployVendor($vendorDir);

        $this->assertTrue($result);
        
        // Verify files were uploaded
        $this->assertContains("vendor-{$token}.zip", $uploader->uploadedFiles);
        $this->assertContains("sharedsync-vendor-{$token}.php", $uploader->uploadedFiles);
        
        // Verify cleanup
        $this->assertContains("sharedsync-vendor-{$token}.php", $uploader->deletedFiles);
        $this->assertContains("vendor-{$token}.zip", $uploader->deletedFiles);

        // Clean up local test dir
        File::deleteDirectory($vendorDir);
    }

    public function test_vendor_manager_handles_extraction_failure()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $baseUrl = 'http://example.com';
        $manager = new VendorManager($uploader, $baseUrl, $output);
        $token = 'fail-token';
        $manager->setToken($token);

        $vendorDir = base_path('vendor-test-fail');
        if (!is_dir($vendorDir)) {
            mkdir($vendorDir, 0777, true);
        }
        file_put_contents($vendorDir . '/test.txt', 'content');

        Http::fake([
            'http://example.com/sharedsync-vendor-fail-token*' => Http::response(['status' => 'error', 'error' => 'Extraction failed'], 500),
        ]);

        $result = $manager->deployVendor($vendorDir);

        $this->assertFalse($result);
        $this->assertStringContainsString('Remote vendor extraction failed', $output->fetch());

        // Verify cleanup WAS called on remote files even if it failed
        $this->assertContains("sharedsync-vendor-{$token}.php", $uploader->deletedFiles);
        $this->assertContains("vendor-{$token}.zip", $uploader->deletedFiles);

        File::deleteDirectory($vendorDir);
    }
}
