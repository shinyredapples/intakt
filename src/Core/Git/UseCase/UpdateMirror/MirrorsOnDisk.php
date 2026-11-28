<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Git\Contract\MirrorFolders;
use Intakt\Core\Git\Domain\Mirrors;
use NoDiscard;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessFailure;

final readonly class MirrorsOnDisk implements MirrorFolders
{
    private const string SUFFIX = '.git';

    public function __construct(
        private UpdateMirror $update,
        private Mirrors $mirrors,
    ) {
    }

    #[NoDiscard]
    public function existing(): array
    {
        $directory = $this->mirrors->directory;
        if (!is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        // Listed by name and never by a pattern, because a pattern would give brackets in the install's path a meaning.
        $entries = scandir($directory);

        return false === $entries ? [] : array_values(array_filter(array_map($this->mirrorNamed(...), $entries)));
    }

    private function mirrorNamed(string $entry): ?string
    {
        if (!str_ends_with($entry, self::SUFFIX)) {
            return null;
        }

        try {
            // Through the update's own naming, so a staging folder or any other name no update writes to is left out.
            $path = $this->mirrors->pathOf(substr($entry, 0, -strlen(self::SUFFIX)));

            return $this->update->isMirror($path) ? $path : null;
        } catch (RuntimeException|ProcessFailure) {
            // Because an update of a folder whose name is refused or whose check cannot be answered fails on that, before it measures any disk.
            return null;
        }
    }
}
