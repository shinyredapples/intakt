<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Closure;

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

    private const string MODE = '/^(?:index [0-9a-f]+\.\.[0-9a-f]+ |(?:new file|deleted file|old|new) mode )([0-7]+)$/m';

    // A plain file only, because an executable file or a link is run or followed by its mode alone.
    private const string NOT_RUNNABLE_MODE = '100644';

    // A closing quote may follow, because git quotes a path with a byte outside printable ASCII, a quote or a backslash; an escape never holds a letter after a dot, and a path that ends in a quote of its own ends in an escaped one, so neither is read as an image name.
    private const string EXTENSION = '/\A[^\n]*\.([A-Za-z]+)"?\n/';

    // The bytes each image starts with, by the extension of its name, so that neither a script named like an image nor an image named like a script is named.
    private const array IMAGE_SIGNATURES = [
        'png' => "\x89PNG\r\n\x1a\n",
        'jpg' => "\xFF\xD8\xFF",
        'jpeg' => "\xFF\xD8\xFF",
        'gif' => 'GIF8',
    ];

    /**
     * @param Closure(string): string $blob
     */
    public function named(string $patch, Closure $blob): string
    {
        return $this->eachFile($patch, fn (string $file): string => $this->binaryNamed($file, $blob) ?? $this->textShown($file));
    }

    // For a reader that refuses what is not UTF-8 text itself and approves what may run: only a plain image is named, because any other file with a NUL byte can still be a script an interpreter runs, so it stays for the reader to refuse; an escaped byte would show it a text file that differs from the one approved.
    /**
     * @param Closure(string): string $blob
     */
    public function withImagesNamed(string $patch, Closure $blob): string
    {
        return $this->eachFile($patch, fn (string $file): string => $this->imageNamed($file, $blob) ?? $file);
    }

    public function isBinary(string $bytes): bool
    {
        return str_contains($bytes, "\0");
    }

    // A replacement character of the text itself is escaped too, so an escape always stands for exactly one set of bytes.
    public function escaped(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return (string) preg_replace_callback(
            self::CHARACTER_OR_BYTE,
            static fn (array $match): string => '' !== ($match['character'] ?? '') && self::REPLACEMENT !== $match['character']
                ? $match['character']
                : sprintf('%s{%s}', self::REPLACEMENT, strtoupper(bin2hex($match[0]))),
            $text,
        );
    }

    /**
     * @param Closure(string): string $blob
     */
    private function binaryNamed(string $file, Closure $blob): ?string
    {
        $header = $this->blobHeader($file);

        return null === $header ? null : $this->namedWhen($header, $blob, static fn (string $bytes): bool => true);
    }

    /**
     * @param Closure(string): string $blob
     */
    private function imageNamed(string $file, Closure $blob): ?string
    {
        $header = $this->blobHeader($file);
        $signature = 1 === preg_match(self::EXTENSION, $file, $extension) ? self::IMAGE_SIGNATURES[strtolower($extension[1])] ?? null : null;

        return null === $header || null === $signature || !$this->isPlainFile($header[0])
            ? null
            : $this->namedWhen($header, $blob, static fn (string $bytes): bool => str_starts_with($bytes, $signature));
    }

    private function isPlainFile(string $header): bool
    {
        preg_match_all(self::MODE, $header, $modes);

        return [] !== $modes[1] && array_all($modes[1], static fn (string $mode): bool => self::NOT_RUNNABLE_MODE === $mode);
    }

    /**
     * @return ?array{0: string, 1: string, 2: string}
     */
    private function blobHeader(string $file): ?array
    {
        return 1 === preg_match(self::HEADER, $file, $header) && 1 !== preg_match(self::SUBMODULE, $file) ? $header : null;
    }

    /**
     * @param array{0: string, 1: string, 2: string} $header
     * @param Closure(string): string                $blob
     * @param Closure(string): bool                  $eachBlob
     */
    private function namedWhen(array $header, Closure $blob, Closure $eachBlob): ?string
    {
        $base = $this->bytesOf($header[1], $blob);
        $head = $this->bytesOf($header[2], $blob);
        $present = array_filter([$base, $head], static fn (?string $bytes): bool => null !== $bytes);

        return array_any($present, $this->isBinary(...)) && array_all($present, $eachBlob)
            ? sprintf("%sBinary file, not shown: %s at the base, %s at the head\n", $header[0], $this->sizeOf($base), $this->sizeOf($head))
            : null;
    }

    private function textShown(string $file): string
    {
        return null === $this->blobHeader($file) ? $this->withoutBlobs($file) : $this->escaped($file);
    }

    /**
     * @param Closure(string): string $shown
     */
    private function eachFile(string $patch, Closure $shown): string
    {
        $files = preg_split(self::FILE_START, $patch);

        if (false === $files) {
            throw new ForgeReadFailed('The diff could not be split into its files.');
        }

        return implode('', array_map($shown, $files));
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
        if (null === $bytes) {
            return 'absent';
        }

        return 1 === strlen($bytes) ? '1 byte' : strlen($bytes).' bytes';
    }
}
