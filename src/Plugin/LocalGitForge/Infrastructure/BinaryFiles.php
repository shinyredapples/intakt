<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// A file is binary when its part of the patch holds a NUL byte or is not UTF-8, because its bytes would break every prompt, tool answer and ledger payload that must be UTF-8.
final readonly class BinaryFiles
{
    // A line of a file's content always starts with its marker, so only git's own header starts a line with "diff --git ".
    private const string FILE_START = '/^(?=diff --git )/m';

    // Up to the end of the index line, which --full-index makes carry both whole blob ids.
    private const string HEADER = '/\A.*?^index ([0-9a-f]+)\.\.([0-9a-f]+)[^\n]*\n/ms';

    private const string ABSENT = '/\A0+\z/';

    /**
     * @param Closure(string): int $sizeOf the size in bytes of a blob, by its id
     */
    public function named(string $patch, Closure $sizeOf): string
    {
        $files = preg_split(self::FILE_START, $patch) ?: [$patch];

        return implode('', array_map(fn (string $file): string => $this->isText($file) ? $file : $this->binary($file, $sizeOf), $files));
    }

    private function isText(string $file): bool
    {
        return !str_contains($file, "\0") && mb_check_encoding($file, 'UTF-8');
    }

    /**
     * @param Closure(string): int $sizeOf
     */
    private function binary(string $file, Closure $sizeOf): string
    {
        if (1 !== preg_match(self::HEADER, $file, $header)) {
            throw new ForgeReadFailed('git showed a file that is not text without the ids of its blobs.');
        }

        return sprintf("%sBinary file, not shown: %s at the base, %s at the head\n", $header[0], $this->sizeIn($header[1], $sizeOf), $this->sizeIn($header[2], $sizeOf));
    }

    /**
     * @param Closure(string): int $sizeOf
     */
    private function sizeIn(string $blob, Closure $sizeOf): string
    {
        return 1 === preg_match(self::ABSENT, $blob) ? 'absent' : $sizeOf($blob).' bytes';
    }
}
