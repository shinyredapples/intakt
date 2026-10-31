<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class Diff
{
    public function __construct(
        public string $patch,
        public ChangedFiles $files,
    ) {
    }

    public function addedLines(): int
    {
        return $this->countLinesStartingWith('+');
    }

    public function removedLines(): int
    {
        return $this->countLinesStartingWith('-');
    }

    private function countLinesStartingWith(string $marker): int
    {
        // Only lines inside a hunk are counted, because the "+++" and "---" file headers look like a changed line "++x" or "--x".
        $count = 0;
        $inHunk = false;

        foreach (explode("\n", $this->patch) as $line) {
            $inHunk = $this->hunkStateAt($line) ?? $inHunk;
            $count += $inHunk && str_starts_with($line, $marker) ? 1 : 0;
        }

        return $count;
    }

    private function hunkStateAt(string $line): ?bool
    {
        return match (true) {
            str_starts_with($line, 'diff ') => false,
            str_starts_with($line, '@@') => true,
            default => null,
        };
    }
}
