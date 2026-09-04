<?php

namespace Cslash\SharedSync\Services;

use Cslash\SharedSync\Services\Uploader\UploaderInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Manages remote vendor deployment by using a plain PHP controller
 * temporarily uploaded to the remote public directory.
 *
 * This procedure avoids issues when the remote vendor directory is missing or incomplete.
 */
class VendorManager
{
    protected UploaderInterface $uploader;

    /**
     * URL for remote callback.
     * @var string|mixed
     */
    protected string $url = '';

    /**
     * Vendor local temporary installation path.
     * @var string|null
     */
    protected ?string $tmpPath = null;

    protected ?string $archiveName;
    protected ?string $localArchiveFile;
    protected ?string $remoteArchiveFile;
    protected ?string $controllerStub;
    protected ?string $remoteControllerScript;
    protected ?string $remoteControllerUrl;


    public function __construct(UploaderInterface $uploader) {

        $this->uploader = $uploader;

        if (!$this->uploader->isConnected()) {
            $this->uploader->connect();
        }

        $config = config('sharedsync');

        $url = $config['url'] ?? '';

        $this->tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sharedsync-' . bin2hex(random_bytes(8));
        if (!mkdir($this->tmpPath, 0700, true)) {
            throw new \RuntimeException( 'Unable to create temporary directory: ' . $this->tmpPath );
        }

        $this->archiveName = 'vendor-archive-' . bin2hex(random_bytes(8)) . '.zip';
        $this->localArchiveFile = $this->tmpPath . DIRECTORY_SEPARATOR . $this->archiveName;
        $this->remoteArchiveFile = 'storage/sharedsync/' . $this->archiveName;

        $this->controllerStub = dirname(__DIR__, 2) . '/resources/stubs/vendor-controller.stub';
        $this->remoteControllerScript = 'vendor-controller-' . bin2hex(random_bytes(8)) . '.php';
        $this->remoteControllerUrl = $url . '/' . $this->remoteControllerScript;
    }

    /**
     * Get installed packages from composer.lock or composer.json
     */
    public function list(string $composerFile = 'composer.lock'): array
    {
        $lockFile = base_path() . DIRECTORY_SEPARATOR . $composerFile;

        $packages = [];

        if (!file_exists($lockFile)) {
            return $packages;
        }

        $data = json_decode(file_get_contents($lockFile), true);
        if (!is_array($data)) {
            return $packages;
        }

        if (isset($data['packages']) || isset($data['packages-dev'])) {
            if (isset($data['packages']) && is_array($data['packages'])) {
                foreach ($data['packages'] as $package) {
                    if (isset($package['name']) && isset($package['version'])) {
                        $packages[$package['name']] = $package['version'];
                    }
                }
            }
            if (isset($data['packages-dev']) && is_array($data['packages-dev'])) {
                foreach ($data['packages-dev'] as $package) {
                    if (isset($package['name']) && isset($package['version'])) {
                        $packages[$package['name']] = $package['version'];
                    }
                }
            }
        } elseif (isset($data['require']) || isset($data['require-dev'])) {
            if (isset($data['require']) && is_array($data['require'])) {
                foreach ($data['require'] as $package => $version) {
                    $packages[$package] = $version;
                }
            }
            if (isset($data['require-dev']) && is_array($data['require-dev'])) {
                foreach ($data['require-dev'] as $package => $version) {
                    $packages[$package] = $version;
                }
            }
        }

        ksort($packages);
        return $packages;
    }

    /**
     * Compute differences between composer.json and composer.lock
     */
    public function diff(): array
    {
        $lockFilePackages = $this->list('composer.lock');
        $jsonFilePackages = $this->list('composer.json');

        $notInLock = array_diff_key($jsonFilePackages, $lockFilePackages);
        $different = [];

        foreach ($jsonFilePackages as $name => $version) {
            if (isset($lockFilePackages[$name]) && $lockFilePackages[$name] !== $version) {
                $different[$name] = [
                    'json' => $version,
                    'lock' => $lockFilePackages[$name]
                ];
            }
        }

        return array_merge($notInLock, $different);
    }

    /**
     * Runs composer install.
     */
    public function install(): array
    {
        $output = '';

        copy(base_path() . DIRECTORY_SEPARATOR . 'composer.json', $this->tmpPath . DIRECTORY_SEPARATOR . 'composer.json');
        copy(base_path() . DIRECTORY_SEPARATOR . 'composer.lock', $this->tmpPath . DIRECTORY_SEPARATOR . 'composer.lock');

        $process = new Process(['composer', 'install',
            '--optimize-autoloader',
            '--no-interaction',
            '--no-progress',
            '--no-scripts',
            '--no-plugins',
            '--prefer-dist',
        ]);
        $process->setWorkingDirectory($this->tmpPath);
        $process->setTimeout(600);
        $process->run(function ($type, $buffer) use (&$output) {
            $output .= $buffer;
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('Local composer install failed: ' . $process->getErrorOutput());
        }
        
        return [
            'path' => $this->tmpPath,
            'output' => $output
        ];
    }

    public function clean(): void
    {
        File::deleteDirectory($this->tmpPath);
    }


    /**
     * Deploy the local vendor directory as a zip and extract it remotely.
     */
    public function deploy(): bool
    {

//        $zipFile = null;
//
//        try {
//            $zipFile = $this->compress();
//
//            $this->uploader->put($this->remoteZipName, file_get_contents($zipFile));
//
//            $success = $this->extract($remoteZipName);
//
//            return $success;
//        } finally {
//            if ($zipFile && file_exists($zipFile)) {
//                @unlink($zipFile);
//            }
//        }
        return true;
    }

    /**
     * Compress vendor directory to a temporary zip file.
     */
    public function compress(): void
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('PHP ZipArchive extension is required to deploy vendor.');
        }

        if (!is_dir($this->tmpPath)) {
            throw new \RuntimeException("Local vendor directory not found: {$this->tmpPath}");
        }

        $vendorDir = $this->tmpPath . DIRECTORY_SEPARATOR . 'vendor';
        if (!is_dir($vendorDir)) {
            throw new \RuntimeException("Vendor directory not found: {$vendorDir}");
        }

        $zip = new \ZipArchive();
        if ($zip->open($this->localArchiveFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create zip file: {$this->localArchiveFile}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($vendorDir) + 1);
            $entry = 'vendor/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($item->isDir()) {
                $zip->addEmptyDir($entry);
            } else {
                $zip->addFile($item->getPathname(), $entry);
            }
        }

        $zip->close();

    }

    public function upload()
    {

        if (!file_exists($this->localArchiveFile)) {
            throw new \RuntimeException("Local vendor archive not found. Run compress first.");
        }

        $this->uploader->put($this->remoteArchiveFile, file_get_contents($this->localArchiveFile));
    }

    /**
     * Extract remote vendor zip by uploading the controller stub and calling it.
     */
    public function extract(): array
    {

        // Prepare the controller stub file before uploading it
        $stubContent = str_replace('__SHAREDSYNC_ARCHIVE_NAME__', $this->archiveName, file_get_contents($this->controllerStub));
        $remoteControllerScriptPath = 'public/' . $this->remoteControllerScript;
        $this->uploader->put($remoteControllerScriptPath, $stubContent);

        try {
            $response = Http::timeout(600)
                ->post($this->remoteControllerUrl);

            return $response->json();
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        } finally {
            // cleanup
            $this->uploader->delete([$remoteControllerScriptPath]);
        }
    }

}
