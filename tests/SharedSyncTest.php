<?php

namespace Cslash\SharedSync\Tests;

use Orchestra\Testbench\TestCase;
use Cslash\SharedSync\SharedSyncServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

class SharedSyncTest extends TestCase
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
        $this->tempDir = __DIR__ . '/temp';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        $this->app->setBasePath($this->tempDir);
        
        // Mock storage_path and public_path
        $this->app->useStoragePath($this->tempDir . '/storage');
        $this->app->instance('path.public', $this->tempDir . '/public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_sharedsync_route_unauthorized_without_token()
    {
        $response = $this->postJson('/sharedsync');
        $response->assertStatus(401);
    }

    public function test_sharedsync_route_success()
    {
        $token = 'test-token';
        file_put_contents($this->tempDir . '/.sharedsync-token', $token);

        // Ensure directories exist for the check
        mkdir($this->tempDir . '/storage/app/public', 0777, true);
        mkdir($this->tempDir . '/bootstrap/cache', 0777, true);
        mkdir($this->tempDir . '/public', 0777, true);
        
        // Create the symlink
        symlink($this->tempDir . '/storage/app/public', $this->tempDir . '/public/storage');

        $response = $this->withHeaders(['X-SharedSync-Token' => $token])
            ->postJson('/sharedsync');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'checks' => [
                    'storage' => 'OK',
                    'bootstrap_cache' => 'OK',
                    'public_storage_symlink' => 'OK',
                ]
            ]);
    }

    public function test_sharedsync_route_creates_symlink()
    {
        $token = 'test-token';
        file_put_contents($this->tempDir . '/.sharedsync-token', $token);

        // Ensure directories exist but NOT the symlink
        mkdir($this->tempDir . '/storage/app/public', 0777, true);
        mkdir($this->tempDir . '/bootstrap/cache', 0777, true);
        mkdir($this->tempDir . '/public', 0777, true);

        // We need to mock Artisan::call for storage:link or ensure it works in this environment
        // Since we are in a test environment, let's just see if it works.

        $response = $this->withHeaders(['X-SharedSync-Token' => $token])
            ->postJson('/sharedsync');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'checks' => [
                    'public_storage_symlink' => 'Created',
                ]
            ]);
    }

    public function test_deploy_command_calls_remote_checks()
    {
        Http::fake([
            'https://example.com/sharedsync' => Http::response([
                'status' => 'success',
                'checks' => ['storage' => 'OK']
            ], 200),
        ]);

        $this->app['config']->set('sharedsync', [
            'driver' => 'ftp',
            'ftp' => ['host' => 'localhost', 'username' => 'user', 'password' => 'pass'],
            'url' => 'https://example.com',
            'build' => ['composer' => false, 'npm' => false, 'artisan_cache' => false],
            'ignore' => [],
            'options' => ['delete_removed' => true],
        ]);

        $mockUploader = new MockUploader();
        $this->app->bind('sharedsync.uploader', function() use ($mockUploader) {
            return $mockUploader;
        });

        file_put_contents($this->tempDir . '/file1.txt', 'content');

        $this->artisan('sharedsync:deploy')
            ->expectsOutput('Running remote checks...')
            ->expectsOutput('Remote checks passed successfully.')
            ->expectsOutput('- storage: OK')
            ->assertExitCode(0);

        // Verify token was uploaded and then deleted
        $this->assertContains('.sharedsync-token', $mockUploader->uploadedFiles);
        $this->assertContains('.sharedsync-token', $mockUploader->deletedFiles);
    }

    public function test_check_command_runs_remote_checks()
    {
        Http::fake([
            'https://example.com/sharedsync' => Http::response([
                'status' => 'success',
                'checks' => ['storage' => 'OK', 'bootstrap_cache' => 'OK']
            ], 200),
        ]);

        $this->app['config']->set('sharedsync', [
            'driver' => 'ftp',
            'ftp' => ['host' => 'localhost', 'username' => 'user', 'password' => 'pass'],
            'url' => 'https://example.com',
        ]);

        $mockUploader = new MockUploader();
        $this->app->bind('sharedsync.uploader', function() use ($mockUploader) {
            return $mockUploader;
        });

        $this->artisan('sharedsync:check')
            ->expectsOutput('Running remote checks...')
            ->expectsOutput('Remote checks passed successfully.')
            ->expectsOutput('- storage: OK')
            ->expectsOutput('- bootstrap_cache: OK')
            ->assertExitCode(0);

        // Verify token was uploaded and then deleted
        $this->assertContains('.sharedsync-token', $mockUploader->uploadedFiles);
        $this->assertContains('.sharedsync-token', $mockUploader->deletedFiles);
    }
}
