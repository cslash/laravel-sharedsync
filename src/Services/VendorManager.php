<?php

namespace Cslash\SharedSync\Services;

use Cslash\SharedSync\Services\Uploader\UploaderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Manages remote vendor deployment outside of Laravel.
 *
 * The flow is intentionally self-contained so that it works even when
 * the remote vendor/ directory is missing or incomplete:
 *  1. Generate a one-time token and render the bundled stub into a
 *     unique PHP file under public/.
 *  2. Upload the stub (and optionally a zipped vendor directory) via
 *     the active uploader.
 *  3. Call the stub over HTTP with the token to extract the zip and/or
 *     run composer install.
 *  4. Delete the stub (and the zip) from the remote server.
 */
class VendorManager
{
    protected UploaderInterface $uploader;
    protected OutputInterface $output;
    protected string $baseUrl;
    protected ?string $token = null;

    public function __construct(UploaderInterface $uploader, string $baseUrl, OutputInterface $output)
    {
        $this->uploader = $uploader;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->output = $output;
    }

    /**
     * Set the authentication token for the current session.
     */
    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    /**
     * Get installed packages from composer.lock
     */
    public function getInstalledPackages(string $path): array
    {
        $lockFile = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.lock';
        
        if (!file_exists($lockFile)) {
            $this->output->writeln('<comment>composer.lock not found at: ' . $lockFile . '</comment>');
            return [];
        }

        $data = json_decode(file_get_contents($lockFile), true);
        $packages = [];

        if (isset($data['packages'])) {
            foreach ($data['packages'] as $package) {
                $packages[$package['name']] = $package['version'];
            }
        }

        ksort($packages);
        return $packages;
    }

    /**
     * Build vendor locally in a temporary directory
     */
    public function buildLocal(string $projectPath): string
    {
        $this->output->writeln('<info>Building vendor locally...</info>');

        $process = new Process(['composer', 'install', '--optimize-autoloader']);
        $process->setWorkingDirectory($projectPath);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Local composer install failed: ' . $process->getErrorOutput());
        }

        return $projectPath . DIRECTORY_SEPARATOR . 'vendor';
    }

    /**
     * Deploy the local vendor directory as a zip and extract it remotely.
     */
    public function deployVendor(string $localVendorDir): bool
    {
        if (!is_dir($localVendorDir)) {
            $this->output->writeln("<error>Local vendor directory not found: {$localVendorDir}</error>");
            return false;
        }

        if (!$this->token) {
            $this->output->writeln("<error>No token set for vendor deployment.</error>");
            return false;
        }

        $zipFile = tempnam(sys_get_temp_dir(), 'sharedsync-vendor-') . '.zip';
        $remoteZipName = "vendor-{$this->token}.zip";
        $remoteStubName = "sharedsync-vendor-{$this->token}.php";

        try {
            $this->output->writeln('<info>Zipping vendor directory...</info>');
            $this->zipDirectory($localVendorDir, $zipFile, 'vendor');

            $this->output->writeln('<info>Uploading vendor zip...</info>');
            $this->uploader->put($remoteZipName, file_get_contents($zipFile));

            $this->output->writeln('<info>Uploading extraction stub...</info>');
            $stubContent = file_get_contents(__DIR__ . '/../../resources/stubs/vendor-extractor.stub');
            $stubContent = str_replace('__SHAREDSYNC_TOKEN__', $this->token, $stubContent);
            $this->uploader->put($remoteStubName, $stubContent);

            $this->output->writeln('<info>Triggering remote vendor extraction...</info>');
            $success = $this->callRemoteExtraction($remoteStubName, $remoteZipName);

            // Clean up remote extraction files (always attempt if they were uploaded)
            $this->output->writeln('<info>Cleaning up remote extraction files...</info>');
            try {
                $this->uploader->delete([$remoteStubName, $remoteZipName]);
            } catch (\Exception $e) {
                $this->output->writeln('<comment>Warning: Could not delete remote extraction files: ' . $e->getMessage() . '</comment>');
            }

            return $success;

        } finally {
            if (file_exists($zipFile)) {
                @unlink($zipFile);
            }
        }
    }

    protected function zipDirectory(string $sourceDir, string $zipFile, string $insidePrefix): void
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP ZipArchive extension is required to deploy vendor.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create zip file: {$zipFile}");
        }

        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($sourceDir) + 1);
            $entry = $insidePrefix . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($item->isDir()) {
                $zip->addEmptyDir($entry);
            } else {
                $zip->addFile($item->getPathname(), $entry);
            }
        }

        $zip->close();
    }

    protected function callRemoteExtraction(string $remoteStubName, string $remoteZipName): bool
    {
        if (empty($this->baseUrl)) {
            $this->output->writeln('<error>No deployment URL configured; cannot reach extraction stub.</error>');
            return false;
        }

        $url = $this->baseUrl . '/' . $remoteStubName;

        try {
            $response = Http::timeout(600)
                ->get($url, [
                    'token' => $this->token,
                    'action' => 'extract',
                    'zip' => $remoteZipName,
                ]);

            if ($response->failed()) {
                $body = $response->body();
                try {
                    $data = $response->json();
                    $msg = $data['error'] ?? $data['message'] ?? $body;
                } catch (\Exception $e) {
                    // Not JSON, probably HTML. Strip tags to keep it readable.
                    $msg = strip_tags($body);
                    $msg = Str::limit(trim($msg), 500);
                }
                
                $this->output->writeln('<error>Remote vendor extraction failed: ' . $msg . '</error>');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->output->writeln('<error>Failed to call extraction stub: ' . $e->getMessage() . '</error>');
            return false;
        }
    }
}
