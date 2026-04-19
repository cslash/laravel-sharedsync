<?php

namespace Cslash\SharedSync\Services;

class Manifest
{
    protected string $filePath;

    public function __construct(string $basePath)
    {
        $this->filePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.deploy-manifest.json';
    }

    public function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $content = file_get_contents($this->filePath);
        return json_decode($content, true) ?: [];
    }

    public function save(array $files): void
    {
        $data = [];
        foreach ($files as $file) {
            $data[$file['path']] = [
                'hash' => $file['hash'],
                'mtime' => $file['mtime']
            ];
        }

        file_put_contents($this->filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function compare(array $currentFiles, array $lastManifest): array
    {
        $toUpload = [];
        $toDelete = [];

        $currentPaths = [];
        foreach ($currentFiles as $file) {
            $path = $file['path'];
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
            if (!in_array($path, $currentPaths)) {
                $toDelete[] = $path;
            }
        }

        return [
            'upload' => $toUpload,
            'delete' => $toDelete,
        ];
    }
}
