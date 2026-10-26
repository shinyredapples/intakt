<?php

declare(strict_types=1);

namespace Intakt\Gates;

use Symfony\Component\Filesystem\Filesystem;

final readonly class ScratchDirectory
{
    private function __construct(public string $path)
    {
    }

    public static function create(string $prefix): ?self
    {
        $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));

        return mkdir($path, 0o700) ? new self($path) : null;
    }

    public function remove(): void
    {
        new Filesystem()->remove($this->path);
    }
}
