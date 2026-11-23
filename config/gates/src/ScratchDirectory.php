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

    // Because a PHP whose CLI prints warnings on stdout would put one between bin/check's JSON lines, a temp directory that is missing or read-only only gives no folder.
    public static function create(string $prefix): ?self
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));

        return @mkdir($path, 0o700) ? new self($path) : null;
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->path);
    }

    // Because a folder left behind in the system temp directory costs nothing, while a cleanup that throws would cost bin/check its verdict.
    public function discard(): void
    {
        try {
            $this->remove();
        } catch (IOException) {
        }
    }
}
