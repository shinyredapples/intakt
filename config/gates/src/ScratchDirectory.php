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

    public static function create(string $prefix): ?self
    {
        $temp = sys_get_temp_dir();

        // Because the check prints only JSON lines, a missing or read-only temp folder must give no PHP warning, and must not be created in passing.
        if (!is_dir($temp) || !is_writable($temp)) {
            return null;
        }

        $path = $temp.'/'.$prefix.'-'.bin2hex(random_bytes(8));

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
