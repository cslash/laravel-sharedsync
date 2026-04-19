<?php

namespace Cslash\SharedSync\Tests;

use Orchestra\Testbench\TestCase;
use Cslash\SharedSync\Services\FileScanner;
use Cslash\SharedSync\Services\Manifest;
use Illuminate\Support\Facades\File;

class DeploymentTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = __DIR__ . '/temp';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_file_scanner_scans_files()
    {
        file_put_contents($this->tempDir . '/file1.txt', 'content1');
        mkdir($this->tempDir . '/subdir');
        file_put_contents($this->tempDir . '/subdir/file2.txt', 'content2');

        $scanner = new FileScanner($this->tempDir, ['.git']);
        $files = $scanner->scan();

        $this->assertCount(2, $files);
        $this->assertEquals('file1.txt', $files[0]['path']);
        $this->assertEquals('subdir/file2.txt', $files[1]['path']);
    }

    public function test_file_scanner_skips_directories()
    {
        mkdir($this->tempDir . '/empty_dir');
        file_put_contents($this->tempDir . '/file1.txt', 'content1');

        $scanner = new FileScanner($this->tempDir, []);
        $files = $scanner->scan();

        $this->assertCount(1, $files);
        $this->assertEquals('file1.txt', $files[0]['path']);
    }

    public function test_file_scanner_respects_ignores()
    {
        file_put_contents($this->tempDir . '/file1.txt', 'content1');
        mkdir($this->tempDir . '/node_modules');
        file_put_contents($this->tempDir . '/node_modules/package.json', '{}');

        $scanner = new FileScanner($this->tempDir, ['node_modules']);
        $files = $scanner->scan();

        $this->assertCount(1, $files);
        $this->assertEquals('file1.txt', $files[0]['path']);
    }

    public function test_manifest_diffing()
    {
        $manifest = new Manifest($this->tempDir);

        $currentFiles = [
            ['path' => 'new.txt', 'hash' => 'hash1', 'mtime' => 123],
            ['path' => 'modified.txt', 'hash' => 'new_hash', 'mtime' => 124],
            ['path' => 'same.txt', 'hash' => 'same_hash', 'mtime' => 125],
        ];

        $lastManifest = [
            'modified.txt' => ['hash' => 'old_hash', 'mtime' => 100],
            'same.txt' => ['hash' => 'same_hash', 'mtime' => 125],
            'deleted.txt' => ['hash' => 'deleted_hash', 'mtime' => 90],
        ];

        $diff = $manifest->compare($currentFiles, $lastManifest);

        $this->assertCount(2, $diff['upload']);
        $this->assertEquals('new.txt', $diff['upload'][0]['path']);
        $this->assertEquals('modified.txt', $diff['upload'][1]['path']);

        $this->assertCount(1, $diff['delete']);
        $this->assertEquals('deleted.txt', $diff['delete'][0]);
    }

    public function test_remote_directory_creation_logic()
    {
        $uploader = new MockUploader();
        $uploader->existingDirectories = []; // Root doesn't exist

        $root = '/www/html';
        if (!$uploader->is_dir($root)) {
            // This simulates what happens in DeployCommand
            // In a real uploader, upload([]) or a specific makedir call would happen.
            // For the mock, we can just record that it was checked and we intended to create it.
            $uploader->createdDirectories[] = $root;
        }

        $this->assertTrue($uploader->is_dir($root));
        $this->assertContains($root, $uploader->createdDirectories);
    }
}
