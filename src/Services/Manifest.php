<?php

namespace Cslash\SharedSync\Services;

class Manifest
{
    protected string $filePath;
    protected string $basePath;
    protected array $ignores = [];

    public function __construct(string $basePath, array $ignores = [])
    {
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->filePath = $this->basePath . DIRECTORY_SEPARATOR . '.deploy-manifest.json';
        $this->ignores = $this->loadDeployIgnore($ignores);
    }

    public function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        return json_decode($content, true) ?: [];
    }

    public function save(array $files, array $meta = []): void
    {
        $data = [];
        foreach ($files as $file) {
            $data[$file['path']] = [
                'hash' => $file['hash'],
                'mtime' => $file['mtime']
            ];
        }

        if (!empty($meta)) {
            $data['__meta__'] = $meta;
        }

        file_put_contents($this->filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function getMeta(): array
    {
        $data = $this->load();
        return $data['__meta__'] ?? [];
    }

    public function compare(array $currentFiles, array $lastManifest, ?array $ignores = null): array
    {
        $effectiveIgnores = $ignores !== null ? $this->loadDeployIgnore($ignores) : $this->ignores;

        $toUpload = [];
        $toDelete = [];

        $currentPaths = [];
        foreach ($currentFiles as $file) {
            $path = $file['path'];
            if ($this->shouldIgnore($path, $effectiveIgnores)) {
                continue;
            }

            $currentPaths[] = $path;

            if (!isset($lastManifest[$path])) {
                $toUpload[] = $file;
                continue;
            }

            if ($lastManifest[$path]['hash'] !== $file['hash']) {
                $toUpload[] = $file;
            }
        }

        foreach ($lastManifest as $path => $data) {
            if ($path === '__meta__') {
                continue;
            }
            if ($this->shouldIgnore($path, $effectiveIgnores)) {
                continue;
            }
            if (!in_array($path, $currentPaths)) {
                $toDelete[] = $path;
            }
        }

        return [
            'upload' => $toUpload,
            'delete' => $toDelete,
        ];
    }

    public function shouldIgnore(string $path, ?array $ignores = null): bool
    {
        $ignoresList = $ignores !== null ? $ignores : $this->ignores;
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        foreach ($ignoresList as $ignore) {
            if ($ignore === '') {
                continue;
            }

            $ignore = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ignore);

            // Exact match
            if ($normalizedPath === $ignore || $normalizedPath === ltrim($ignore, DIRECTORY_SEPARATOR) || $normalizedPath === rtrim($ignore, DIRECTORY_SEPARATOR)) {
                return true;
            }

            // Directory match (e.g., node_modules, tests/)
            $dirIgnore = rtrim(ltrim($ignore, DIRECTORY_SEPARATOR), DIRECTORY_SEPARATOR);
            if ($dirIgnore !== '' && (str_starts_with($normalizedPath, $dirIgnore . DIRECTORY_SEPARATOR) || $normalizedPath === $dirIgnore)) {
                return true;
            }

            // Wildcard match
            if (str_contains($ignore, '*')) {
                if (fnmatch($ignore, $normalizedPath) || fnmatch(ltrim($ignore, DIRECTORY_SEPARATOR), $normalizedPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getIgnores(): array
    {
        return $this->ignores;
    }

    public function setIgnores(array $ignores): self
    {
        $this->ignores = $this->loadDeployIgnore($ignores);
        return $this;
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

        return array_values(array_merge($defaultIgnores, $customIgnores));
    }
}
