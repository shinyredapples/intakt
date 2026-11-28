<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowLanes;

use Intakt\Core\Git\Contract\MirrorFolders;
use Intakt\Core\Git\Contract\MirrorPaths;

// Hand-written, because the tests that use it are about whether the list was asked for at all.
final class RecordingMirrorFolders implements MirrorFolders
{
    public bool $asked = false;

    /** @var list<string> */
    private readonly array $paths;

    public function __construct(string ...$paths)
    {
        $this->paths = array_values($paths);
    }

    public function existing(): MirrorPaths
    {
        $this->asked = true;

        return new MirrorPaths(...$this->paths);
    }
}
