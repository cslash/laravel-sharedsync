<?php

namespace Cslash\SharedSync\Tests;

use Orchestra\Testbench\TestCase;
use Cslash\SharedSync\SharedSyncServiceProvider;
use Cslash\SharedSync\Services\Manifest;
use Illuminate\Support\Facades\File;

class DiffCommandTest extends TestCase
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
        $this->tempDir = __DIR__ . '/temp_diff';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
        $this->app->setBasePath($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_diff_command_shows_only_changed_files_by_default()
    {
        $this->app['config']->set('sharedsync', ['ignore' => []]);

        file_put_contents($this->tempDir . '/changed.txt', 'new content');
        file_put_contents($this->tempDir . '/unchanged.txt', 'same content');
        file_put_contents($this->tempDir . '/new.txt', 'fresh content');
        
        $manifest = new Manifest($this->tempDir);
        $manifest->save([
            ['path' => 'changed.txt', 'hash' => md5('old content'), 'mtime' => time() - 100],
            ['path' => 'unchanged.txt', 'hash' => md5('same content'), 'mtime' => time() - 100],
        ]);

        $this->artisan('sharedsync:diff')
            ->expectsOutputToContain('U changed.txt')
            ->expectsOutputToContain('N new.txt')
            ->doesntExpectOutputToContain('  unchanged.txt')
            ->assertExitCode(0);
    }

    public function test_diff_command_shows_all_files_with_all_option()
    {
        $this->app['config']->set('sharedsync', ['ignore' => []]);

        file_put_contents($this->tempDir . '/changed.txt', 'new content');
        file_put_contents($this->tempDir . '/unchanged.txt', 'same content');
        
        $manifest = new Manifest($this->tempDir);
        $manifest->save([
            ['path' => 'changed.txt', 'hash' => md5('old content'), 'mtime' => time() - 100],
            ['path' => 'unchanged.txt', 'hash' => md5('same content'), 'mtime' => time() - 100],
        ]);

        $this->artisan('sharedsync:diff', ['--all' => true])
            ->expectsOutputToContain('U changed.txt')
            ->expectsOutputToContain('  unchanged.txt')
            ->assertExitCode(0);
    }

    public function test_diff_command_pagination()
    {
        $this->app['config']->set('sharedsync', ['ignore' => []]);

        for ($i = 1; $i <= 5; $i++) {
            file_put_contents($this->tempDir . "/file{$i}.txt", "content {$i}");
        }
        
        // No manifest, so all are new
        
        $this->artisan('sharedsync:diff', ['--limit' => 2])
            ->expectsOutput('Scanning files and comparing with manifest...')
            ->expectsOutput('Deployment Diff:')
            ->expectsOutput('----------------------------------------')
            ->expectsOutput('N file1.txt')
            ->expectsOutput('N file2.txt')
            ->expectsQuestion('Show more?', true)
            ->expectsOutput('N file3.txt')
            ->expectsOutput('N file4.txt')
            ->expectsQuestion('Show more?', false)
            ->expectsOutput('----------------------------------------')
            ->expectsOutput('Summary: 5 new, 0 to update, 0 unchanged.')
            ->assertExitCode(0);
    }
}
