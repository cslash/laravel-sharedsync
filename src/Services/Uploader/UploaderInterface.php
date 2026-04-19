<?php

namespace Cslash\SharedSync\Services\Uploader;

interface UploaderInterface
{
    public function connect(): void;
    public function upload(array $files): void;
    public function delete(array $files): void;
    public function list(string $path): array;
    public function disconnect(): void;
    public function is_dir(string $path): bool;
}
