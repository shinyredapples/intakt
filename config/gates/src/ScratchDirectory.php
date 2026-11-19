<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class ScratchDirectory
{
    private function __construct(public string $path)
    {
    }

    // Because a PHP warning would reach bin/check's stdout under display_errors, a temp directory that is missing or read-only only gives no folder.
    public static function create(string $prefix): ?self
    {
        $directory = sys_get_temp_dir();

        if (!is_dir($directory) || !is_writable($directory)) {
            return null;
        }

        $path = $directory.'/'.$prefix.'-'.bin2hex(random_bytes(8));

        try {
            new Filesystem()->mkdir($path, 0o700);
        } catch (IOException) {
            return null;
        }

        return new self($path);
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->path);
    }
}
