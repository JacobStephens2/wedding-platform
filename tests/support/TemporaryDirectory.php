<?php

declare(strict_types=1);

namespace Wedding\Tests\support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TemporaryDirectory
{
    public readonly string $path;

    public function __construct(string $prefix)
    {
        $this->path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
        mkdir($this->path, 0700);
    }

    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->path);
    }
}
