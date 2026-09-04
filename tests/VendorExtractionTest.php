<?php

namespace Cslash\SharedSync\Tests;

use Cslash\SharedSync\Services\Uploader\FtpUploader;
use Cslash\SharedSync\Services\Uploader\SftpUploader;
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

    public function test_ftp_uploader_get_remote_path_with_root()
    {
        $output = new BufferedOutput();
        $uploader = new FtpUploader(['host' => 'localhost', 'username' => 'u', 'password' => 'p', 'root' => '/website'], base_path(), $output);

        $this->assertEquals('/website/storage/sharedsync/file.zip', $uploader->getRemotePath('storage/sharedsync/file.zip'));
        $this->assertEquals('/website/public/controller.php', $uploader->getRemotePath('public/controller.php'));
        $this->assertEquals('/website/.sharedsync-token', $uploader->getRemotePath('.sharedsync-token'));
        $this->assertEquals('/website/storage/sharedsync/file.zip', $uploader->getRemotePath('/website/storage/sharedsync/file.zip'));
    }

    public function test_sftp_uploader_get_remote_path_with_root()
    {
        $output = new BufferedOutput();
        $uploader = new SftpUploader(['host' => 'localhost', 'username' => 'u', 'password' => 'p', 'root' => '/website/'], base_path(), $output);

        $this->assertEquals('/website/storage/sharedsync/file.zip', $uploader->getRemotePath('storage/sharedsync/file.zip'));
        $this->assertEquals('/website/public/controller.php', $uploader->getRemotePath('public/controller.php'));
        $this->assertEquals('/website/.sharedsync-token', $uploader->getRemotePath('.sharedsync-token'));
        $this->assertEquals('/website/storage/sharedsync/file.zip', $uploader->getRemotePath('/website/storage/sharedsync/file.zip'));
    }

    public function test_ftp_uploader_get_remote_path_with_default_root()
    {
        $output = new BufferedOutput();
        $uploader = new FtpUploader(['host' => 'localhost', 'username' => 'u', 'password' => 'p'], base_path(), $output);

        $this->assertEquals('/storage/sharedsync/file.zip', $uploader->getRemotePath('storage/sharedsync/file.zip'));
        $this->assertEquals('/public/controller.php', $uploader->getRemotePath('public/controller.php'));
        $this->assertEquals('/.sharedsync-token', $uploader->getRemotePath('.sharedsync-token'));
    }

    public function test_list_returns_empty_array_when_lock_file_missing()
    {
        $uploader = new MockUploader();
        $manager = new VendorManager($uploader);

        $packages = $manager->list('non_existent_composer.lock');
        $this->assertIsArray($packages);
        $this->assertEmpty($packages);
    }

    public function test_list_parses_composer_lock_file()
    {
        $lockData = [
            'packages' => [
                ['name' => 'laravel/framework', 'version' => 'v10.0.0'],
                ['name' => 'guzzlehttp/guzzle', 'version' => '7.8.0'],
            ],
            'packages-dev' => [
                ['name' => 'phpunit/phpunit', 'version' => '10.5.0'],
            ],
        ];

        $lockFile = base_path('test-composer.lock');
        file_put_contents($lockFile, json_encode($lockData));

        try {
            $uploader = new MockUploader();
            $manager = new VendorManager($uploader);

            $packages = $manager->list('test-composer.lock');

            $this->assertCount(3, $packages);
            $this->assertEquals('7.8.0', $packages['guzzlehttp/guzzle']);
            $this->assertEquals('v10.0.0', $packages['laravel/framework']);
            $this->assertEquals('10.5.0', $packages['phpunit/phpunit']);

            // Check sorting
            $keys = array_keys($packages);
            $this->assertEquals(['guzzlehttp/guzzle', 'laravel/framework', 'phpunit/phpunit'], $keys);
        } finally {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        }
    }

    public function test_list_parses_composer_json_file()
    {
        $jsonData = [
            'require' => [
                'php' => '^8.1',
                'laravel/framework' => '^10.0',
            ],
            'require-dev' => [
                'phpunit/phpunit' => '^10.0',
            ],
        ];

        $jsonFile = base_path('test-composer.json');
        file_put_contents($jsonFile, json_encode($jsonData));

        try {
            $uploader = new MockUploader();
            $manager = new VendorManager($uploader);

            $packages = $manager->list('test-composer.json');

            $this->assertCount(3, $packages);
            $this->assertEquals('^8.1', $packages['php']);
            $this->assertEquals('^10.0', $packages['laravel/framework']);
            $this->assertEquals('^10.0', $packages['phpunit/phpunit']);
        } finally {
            if (file_exists($jsonFile)) {
                unlink($jsonFile);
            }
        }
    }

    public function test_compress_creates_valid_zip()
    {
        $uploader = new MockUploader();
        $manager = new VendorManager($uploader);

        $reflection = new \ReflectionClass($manager);
        $tmpPathProperty = $reflection->getProperty('tmpPath');
        $tmpPathProperty->setAccessible(true);
        $tmpPath = $tmpPathProperty->getValue($manager);

        $localArchiveFileProperty = $reflection->getProperty('localArchiveFile');
        $localArchiveFileProperty->setAccessible(true);
        $localArchiveFile = $localArchiveFileProperty->getValue($manager);

        $vendorDir = $tmpPath . '/vendor';
        mkdir($vendorDir . '/autoload', 0777, true);
        file_put_contents($vendorDir . '/autoload.php', '<?php echo "autoload";');
        file_put_contents($vendorDir . '/autoload/test.php', '<?php echo "test";');

        $manager->compress();

        $this->assertFileExists($localArchiveFile);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($localArchiveFile) === true);
        $this->assertNotFalse($zip->locateName('vendor/autoload.php'));
        $this->assertNotFalse($zip->locateName('vendor/autoload/test.php'));
        $zip->close();

        $manager->clean();
    }

    public function test_compress_throws_when_vendor_dir_missing()
    {
        $uploader = new MockUploader();
        $manager = new VendorManager($uploader);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vendor directory not found');

        $manager->compress();
    }

    public function test_upload_and_extract()
    {
        $this->app['config']->set('sharedsync.url', 'http://example.com');

        $uploader = new MockUploader();
        $manager = new VendorManager($uploader);

        $reflection = new \ReflectionClass($manager);
        $tmpPathProperty = $reflection->getProperty('tmpPath');
        $tmpPathProperty->setAccessible(true);
        $tmpPath = $tmpPathProperty->getValue($manager);

        $localArchiveFileProperty = $reflection->getProperty('localArchiveFile');
        $localArchiveFileProperty->setAccessible(true);
        $localArchiveFile = $localArchiveFileProperty->getValue($manager);

        $remoteArchiveFileProperty = $reflection->getProperty('remoteArchiveFile');
        $remoteArchiveFileProperty->setAccessible(true);
        $remoteArchiveFile = $remoteArchiveFileProperty->getValue($manager);

        $remoteControllerScriptProperty = $reflection->getProperty('remoteControllerScript');
        $remoteControllerScriptProperty->setAccessible(true);
        $remoteControllerScript = $remoteControllerScriptProperty->getValue($manager);

        $vendorDir = $tmpPath . '/vendor';
        mkdir($vendorDir, 0777, true);
        file_put_contents($vendorDir . '/test.txt', 'vendor test');

        $manager->compress();
        $manager->upload();

        $this->assertContains($remoteArchiveFile, $uploader->uploadedFiles);

        Http::fake([
            'http://example.com/' . $remoteControllerScript => Http::response(['status' => 'success', 'message' => 'Extracted'], 200),
        ]);

        $response = $manager->extract();

        $this->assertEquals(['status' => 'success', 'message' => 'Extracted'], $response);
        $this->assertContains('public/' . $remoteControllerScript, $uploader->uploadedFiles);
        $this->assertContains('public/' . $remoteControllerScript, $uploader->deletedFiles);

        $manager->clean();
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
