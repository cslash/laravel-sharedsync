<?php

namespace Cslash\SharedSync\Services;

class FileScanner
{
    protected string $basePath;
    protected array $ignores;

    public function __construct(string $basePath, array $ignores = [])
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->ignores = $this->loadDeployIgnore($ignores);
    }

    public function scan(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->basePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = $this->getRelativePath($file->getPathname());

            if ($this->shouldIgnore($relativePath)) {
                continue;
            }

            $files[$relativePath] = [
                'path' => $relativePath,
                'hash' => md5_file($file->getPathname()),
                'mtime' => $file->getMTime(),
            ];
        }

        ksort($files);
        return array_values($files);
    }

    protected function getRelativePath(string $fullPath): string
    {
        $path = str_replace($this->basePath, '', $fullPath);
        return ltrim($path, DIRECTORY_SEPARATOR);
    }

    protected function shouldIgnore(string $path): bool
    {
        foreach ($this->ignores as $ignore) {
            $ignore = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ignore);
            
            // Exact match
            if ($path === $ignore || $path === ltrim($ignore, DIRECTORY_SEPARATOR)) {
                return true;
            }

            // Directory match (e.g., node_modules)
            $dirIgnore = rtrim(ltrim($ignore, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
            if (str_starts_with($path, $dirIgnore . DIRECTORY_SEPARATOR)) {
                return true;
            }

            // Wildcard match
            if (str_contains($ignore, '*')) {
                if (fnmatch($ignore, $path) || fnmatch(ltrim($ignore, DIRECTORY_SEPARATOR), $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function loadDeployIgnore(array $defaultIgnores): array
    {
        $ignoreFile = $this->basePath . DIRECTORY_SEPARATOR . '.deployignore';
        
        if (!file_exists($ignoreFile)) {
            return $defaultIgnores;
        }

        $content = file_get_contents($ignoreFile);
        $lines = explode("\n", $content);
        
        $customIgnores = array_filter(array_map('trim', $lines), function($line) {
            return !empty($line) && !str_starts_with($line, '#');
        });

        return array_merge($defaultIgnores, $customIgnores);
    }
}
