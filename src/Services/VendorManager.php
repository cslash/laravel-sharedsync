<?php

namespace Cslash\SharedSync\Services;

use Cslash\SharedSync\Services\Uploader\UploaderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

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
    protected string $baseUrl;
    protected OutputInterface $output;

    /** Remote paths, relative to the deploy root. */
    protected string $remoteStubPath;
    protected string $remoteZipPath;
    /** Public URL paths used to reach the stub. */
    protected string $publicStubUrlPath;
    protected string $publicZipName;
    protected string $token;

    public function __construct(UploaderInterface $uploader, string $baseUrl, OutputInterface $output)
    {
        $this->uploader = $uploader;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->output = $output;

        $this->token = Str::random(48);
        $unique = bin2hex(random_bytes(8));
        $stubName = "sharedsync-vendor-{$unique}.php";
        $zipName = "sharedsync-vendor-{$unique}.zip";

        $this->remoteStubPath = 'public/' . $stubName;
        $this->remoteZipPath = 'public/' . $zipName;
        $this->publicStubUrlPath = $stubName;
        $this->publicZipName = $zipName;
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

        $zipFile = tempnam(sys_get_temp_dir(), 'sharedsync-vendor-') . '.zip';
        try {
            $this->output->writeln('<info>Zipping vendor directory...</info>');
            $this->zipDirectory($localVendorDir, $zipFile, 'vendor');

            $this->uploadStub();
            $this->uploadZip($zipFile);

            $this->output->writeln('<info>Triggering remote vendor extraction...</info>');
            $ok = $this->callStub('extract', ['zip' => $this->publicZipName]);

            return $ok;
        } finally {
            if (file_exists($zipFile)) {
                @unlink($zipFile);
            }
            $this->cleanupRemote();
        }
    }

    /**
     * Run `composer install` on the remote server through the stub.
     */
    public function runComposer(): bool
    {
        try {
            $this->uploadStub();
            $this->output->writeln('<info>Triggering remote composer install...</info>');
            return $this->callStub('composer');
        } finally {
            $this->cleanupRemote();
        }
    }

    protected function uploadStub(): void
    {
        $stubTemplate = file_get_contents(__DIR__ . '/../../resources/stubs/vendor-manager.php.stub');
        $stubContent = str_replace('__SHAREDSYNC_TOKEN__', $this->token, $stubTemplate);

        $this->output->writeln('<info>Uploading vendor manager stub...</info>');
        $this->uploader->put($this->remoteStubPath, $stubContent);
    }

    protected function uploadZip(string $localZipFile): void
    {
        $this->output->writeln('<info>Uploading vendor zip...</info>');
        // Use put() so we can stream a file from outside the deploy build path.
        $this->uploader->put($this->remoteZipPath, file_get_contents($localZipFile));
    }

    protected function callStub(string $action, array $query = []): bool
    {
        if (empty($this->baseUrl)) {
            $this->output->writeln('<error>No deployment URL configured; cannot reach vendor manager.</error>');
            return false;
        }

        $url = $this->baseUrl . '/' . $this->publicStubUrlPath;
        $query = array_merge(['action' => $action, 'token' => $this->token], $query);

        try {
            $response = Http::timeout(600)->get($url, $query);

            $data = $response->json();
            if (is_array($data) && isset($data['output']) && $data['output'] !== '') {
                $this->output->writeln($data['output']);
            }

            if ($response->failed() || (is_array($data) && ($data['status'] ?? null) === 'error')) {
                $msg = is_array($data) ? ($data['message'] ?? $response->body()) : $response->body();
                $this->output->writeln('<error>Remote vendor action failed: ' . $msg . '</error>');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->output->writeln('<error>Failed to call vendor manager: ' . $e->getMessage() . '</error>');
            return false;
        }
    }

    protected function cleanupRemote(): void
    {
        try {
            $this->uploader->delete([$this->remoteStubPath, $this->remoteZipPath]);
        } catch (\Exception $e) {
            $this->output->writeln('<comment>Failed to remove vendor manager files: ' . $e->getMessage() . '</comment>');
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
}
