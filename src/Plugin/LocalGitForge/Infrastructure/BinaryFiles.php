<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Closure;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

// A file is binary when one of its blobs holds a NUL byte anywhere, because its bytes would break every prompt and payload that must be UTF-8; a text file keeps its lines, stray bytes escaped, because naming it binary would hide its code.
final readonly class BinaryFiles
{
    // A line of a file's content always starts with its marker, so only git's own header starts a line with "diff --git ".
    private const string FILE_START = '/^(?=diff --git )/m';

    // Kept up to the index line, because --full-index makes it carry both whole blob ids.
    private const string HEADER = '/\A.*?^index ([0-9a-f]+)\.\.([0-9a-f]+)[^\n]*\n/ms';

    private const string ABSENT = '/\A0+\z/';

    // Its blobs are not read, because a submodule's ids name commits of its own repository, which this repository does not hold.
    private const string SUBMODULE = '/^(?:index [0-9a-f]+\.\.[0-9a-f]+ |(?:new file|deleted file|old|new) mode )160000$/m';

    // ASCII is left out of this match of a UTF-8 character or a stray byte, because it is always kept.
    private const string CHARACTER_OR_BYTE = '/(?<character>[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|[\x80-\xFF]/';

    private const string REPLACEMENT = "\u{FFFD}";

    /**
     * @param Closure(string): string $blob
     */
    public function named(string $patch, Closure $blob): string
    {
        $files = preg_split(self::FILE_START, $patch);

        if (false === $files) {
            throw new ForgeReadFailed('The diff could not be split into its files.');
        }

        return implode('', array_map(fn (string $file): string => $this->shown($file, $blob), $files));
    }

    public function isBinary(string $bytes): bool
    {
        return str_contains($bytes, "\0");
    }

    /**
     * @param Closure(string): string $blob
     */
    private function shown(string $file, Closure $blob): string
    {
        if (1 !== preg_match(self::HEADER, $file, $header) || 1 === preg_match(self::SUBMODULE, $file)) {
            return $this->withoutBlobs($file);
        }

        $base = $this->bytesOf($header[1], $blob);
        $head = $this->bytesOf($header[2], $blob);

        if ($this->isBinary($base ?? '') || $this->isBinary($head ?? '')) {
            return sprintf("%sBinary file, not shown: %s at the base, %s at the head\n", $header[0], $this->sizeOf($base), $this->sizeOf($head));
        }

        return $this->escaped($file);
    }

    private function withoutBlobs(string $file): string
    {
        if (str_contains($file, "\0") || !mb_check_encoding($file, 'UTF-8')) {
            throw new ForgeReadFailed('git showed a file that is not text without the ids of its blobs.');
        }

        return $file;
    }

    /**
     * @param Closure(string): string $blob
     */
    private function bytesOf(string $id, Closure $blob): ?string
    {
        return 1 === preg_match(self::ABSENT, $id) ? null : $blob($id);
    }

    private function sizeOf(?string $bytes): string
    {
        return null === $bytes ? 'absent' : strlen($bytes).' bytes';
    }

    // A replacement character of the file itself is escaped too, so an escape always stands for exactly one set of bytes.
    private function escaped(string $file): string
    {
        if (mb_check_encoding($file, 'UTF-8')) {
            return $file;
        }

        return (string) preg_replace_callback(
            self::CHARACTER_OR_BYTE,
            static fn (array $match): string => '' !== ($match['character'] ?? '') && self::REPLACEMENT !== $match['character']
                ? $match['character']
                : sprintf('%s{%s}', self::REPLACEMENT, strtoupper(bin2hex($match[0]))),
            $file,
        );
    }
}
