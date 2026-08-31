<?php

namespace Cslash\SharedSync\Tests;

use Cslash\SharedSync\Services\VendorManager;
use Cslash\SharedSync\SharedSyncServiceProvider;
use Cslash\SharedSync\Tests\MockUploader;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Support\Facades\Http;

class VendorExtractionTest extends TestCase
{
    protected string $tempDir;

    protected function getPackageProviders($app)
    {
        return [
            SharedSyncServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tempDir = sys_get_temp_dir() . '/sharedsync-vendor-test-' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_vendor_manager_getters_and_setters()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir, 'http://example.com/');

        $this->assertEquals('http://example.com', $manager->getBaseUrl());
        $this->assertEquals($this->tempDir, $manager->getPath());
        $this->assertNotEmpty($manager->getToken());

        $manager->setToken('custom-token');
        $this->assertEquals('custom-token', $manager->getToken());

        $manager->setBaseUrl('https://custom.com/');
        $this->assertEquals('https://custom.com', $manager->getBaseUrl());

        $newDir = $this->tempDir . '/sub';
        mkdir($newDir, 0777, true);
        $manager->setPath($newDir);
        $this->assertEquals($newDir, $manager->getPath());
    }

    public function test_vendor_manager_constructor_backward_compatibility()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $baseUrl = 'http://example.com';

        // Old constructor style: ($uploader, $baseUrl, $output)
        $manager = new VendorManager($uploader, $baseUrl, $output);

        $this->assertEquals('http://example.com', $manager->getBaseUrl());
        $this->assertEquals(base_path(), $manager->getPath());
    }

    public function test_list_returns_empty_array_when_lock_file_missing()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir);

        $packages = $manager->list('composer.lock');
        $this->assertIsArray($packages);
        $this->assertEmpty($packages);

        $backwardCompat = $manager->getInstalledPackages($this->tempDir);
        $this->assertIsArray($backwardCompat);
        $this->assertEmpty($backwardCompat);
    }

    public function test_list_parses_composer_lock_file()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir);

        $lockData = [
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v10.0.0'],
                ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.0'],
            ],
            'packages-dev' => [
                ['name' => 'phpunit/phpunit', 'version' => '10.5.0'],
            ],
        ];

        file_put_contents($this->tempDir . '/composer.lock', json_encode($lockData));

        $packages = $manager->list('composer.lock');

        $this->assertCount(3, $packages);
        $this->assertEquals('7.8.0', $packages['guzzlehttp/guzzle']);
        $this->assertEquals('v10.0.0', $packages['laravel/framework']);
        $this->assertEquals('10.5.0', $packages['phpunit/phpunit']);

        // Check sorting
        $keys = array_keys($packages);
        $this->assertEquals(['guzzlehttp/guzzle', 'laravel/framework', 'phpunit/phpunit'], $keys);
    }

    public function test_list_parses_composer_json_file()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir);

        $jsonData = [
            'require' => [
                'php' => '^8.1',
                'laravel/framework' => '^10.0',
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^10.0',
            ],
        ];

        file_put_contents($this->tempDir . '/composer.json', json_encode($jsonData));

        $packages = $manager->list('composer.json');

        $this->assertCount(3, $packages);
        $this->assertEquals('^8.1', $packages['php']);
        $this->assertEquals('^10.0', $packages['laravel/framework']);
        $this->assertEquals('^10.0', $packages['phpunit/phpunit']);
    }

    public function test_diff_identifies_differences_between_json_and_lock()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir);

        $jsonData = [
            'require' => [
                'php' => '^8.1',
                'laravel/framework' => '^10.0',
                'vendor/new-pkg' => '^1.0',
            ],
        ];

        $lockData = [
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v9.0.0'],
            ],
        ];

        file_put_contents($this->tempDir . '/composer.json', json_encode($jsonData));
        file_put_contents($this->tempDir . '/composer.lock', json_encode($lockData));

        $diff = $manager->diff();

        $this->assertArrayHasKey('php', $diff);
        $this->assertEquals('^8.1', $diff['php']);

        $this->assertArrayHasKey('vendor/new-pkg', $diff);
        $this->assertEquals('^1.0', $diff['vendor/new-pkg']);

        $this->assertArrayHasKey('laravel/framework', $diff);
        $this->assertEquals([
            'json' => '^10.0',
            'lock' => 'v9.0.0',
        ], $diff['laravel/framework']);
    }

    public function test_diff_returns_empty_when_packages_match()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir);

        $jsonData = [
            'require' => [
                'laravel/framework' => 'v10.0.0',
            ],
        ];

        $lockData = [
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v10.0.0'],
            ],
        ];

        file_put_contents($this->tempDir . '/composer.json', json_encode($jsonData));
        file_put_contents($this->tempDir . '/composer.lock', json_encode($lockData));

        $diff = $manager->diff();
        $this->assertEmpty($diff);
    }

    public function test_compress_creates_valid_zip()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir);

        $vendorDir = $this->tempDir . '/vendor';
        mkdir($vendorDir . '/autoload', 0777, true);
        file_put_contents($vendorDir . '/autoload.php', '<?php echo "autoload";');
        file_put_contents($vendorDir . '/autoload/test.php', '<?php echo "test";');

        $zipFile = $manager->compress();

        $this->assertFileExists($zipFile);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipFile) === true);
        $this->assertNotFalse($zip->locateName('vendor/autoload.php'));
        $this->assertNotFalse($zip->locateName('vendor/autoload/test.php'));
        $zip->close();

        @unlink($zipFile);
    }

    public function test_compress_throws_when_vendor_dir_missing()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local vendor directory not found');

        $manager->compress();
    }

    public function test_extract_returns_false_when_base_url_is_empty()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $manager = new VendorManager($uploader, $output, $this->tempDir, '');

        $result = $manager->extract('vendor-test.zip');
        $this->assertFalse($result);
        $this->assertStringContainsString('No deployment URL configured', $output->fetch());
    }

    public function test_vendor_manager_uploads_stub_and_calls_it()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $baseUrl = 'http://example.com';
        $manager = new VendorManager($uploader, $output, $this->tempDir, $baseUrl);
        $token = 'test-token-123';
        $manager->setToken($token);

        $vendorDir = $this->tempDir . '/vendor';
        mkdir($vendorDir, 0777, true);
        file_put_contents($vendorDir . '/test.txt', 'content');

        Http::fake([
            'http://example.com/sharedsync-vendor-test-token-123*' => Http::response(['status' => 'success', 'message' => 'Vendor updated successfully.'], 200),
        ]);

        $result = $manager->deploy();

        $this->assertTrue($result);
        
        // Verify files were uploaded
        $this->assertContains("vendor-{$token}.zip", $uploader->uploadedFiles);
        $this->assertContains("sharedsync-vendor-{$token}.php", $uploader->uploadedFiles);
        
        // Verify cleanup
        $this->assertContains("sharedsync-vendor-{$token}.php", $uploader->deletedFiles);
        $this->assertContains("vendor-{$token}.zip", $uploader->deletedFiles);
    }

    public function test_vendor_manager_handles_extraction_failure()
    {
        $uploader = new MockUploader();
        $output = new BufferedOutput();
        $baseUrl = 'http://example.com';
        $manager = new VendorManager($uploader, $output, $this->tempDir, $baseUrl);
        $token = 'fail-token';
        $manager->setToken($token);

        $vendorDir = $this->tempDir . '/vendor';
        mkdir($vendorDir, 0777, true);
        file_put_contents($vendorDir . '/test.txt', 'content');

        Http::fake([
            'http://example.com/sharedsync-vendor-fail-token*' => Http::response(['status' => 'error', 'error' => 'Extraction failed'], 500),
        ]);

        $result = $manager->deploy();

        $this->assertFalse($result);
        $this->assertStringContainsString('Remote vendor extraction failed', $output->fetch());

        // Verify cleanup WAS called on remote files even if it failed
        $this->assertContains("sharedsync-vendor-{$token}.php", $uploader->deletedFiles);
        $this->assertContains("vendor-{$token}.zip", $uploader->deletedFiles);
    }

    public function test_vendor_command_list_action()
    {
        $this->app['config']->set('sharedsync', [
            'driver' => 'ftp',
            'ftp' => ['host' => 'localhost', 'username' => 'user', 'password' => 'pass'],
        ]);

        $mockUploader = new MockUploader();
        $this->app->bind('sharedsync.uploader', function() use ($mockUploader) {
            return $mockUploader;
        });

        $this->artisan('sharedsync:vendor', ['action' => 'list'])
            ->expectsOutput('Installed Packages:')
            ->assertExitCode(0);
    }

    public function test_vendor_command_diff_action()
    {
        $this->app['config']->set('sharedsync', [
            'driver' => 'ftp',
            'ftp' => ['host' => 'localhost', 'username' => 'user', 'password' => 'pass'],
        ]);

        $mockUploader = new MockUploader();
        $this->app->bind('sharedsync.uploader', function() use ($mockUploader) {
            return $mockUploader;
        });

        $this->artisan('sharedsync:vendor', ['action' => 'diff'])
            ->assertExitCode(0);
    }

    public function test_vendor_command_invalid_action()
    {
        $this->app['config']->set('sharedsync', [
            'driver' => 'ftp',
            'ftp' => ['host' => 'localhost', 'username' => 'user', 'password' => 'pass'],
        ]);

        $mockUploader = new MockUploader();
        $this->app->bind('sharedsync.uploader', function() use ($mockUploader) {
            return $mockUploader;
        });

        $this->artisan('sharedsync:vendor', ['action' => 'unknown'])
            ->expectsOutput('Invalid action provided. Available actions: list, diff, install, deploy.')
            ->assertExitCode(1);
    }
}
