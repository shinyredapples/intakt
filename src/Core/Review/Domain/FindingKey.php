<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// A finding as it stands at a head: its text and the content of every file it names, so the same finding on unchanged files is recognized and any edit of a named file gives another key.
final readonly class FindingKey
{
    public const string ALGORITHM = 'sha256';

    private function __construct(public string $value)
    {
    }

    /**
     * @param array<string, string> $fileHashes content hash by checkout-relative path
     */
    public static function of(string $text, array $fileHashes): self
    {
        ksort($fileHashes, SORT_STRING);
        $parts = [self::part($text)];

        foreach ($fileHashes as $path => $hash) {
            $parts[] = self::part((string) $path).self::part($hash);
        }

        return new self(hash(self::ALGORITHM, implode('', $parts)));
    }

    // Length-prefixed, because a path or the text may hold any separator, and two different sets of files must never give one key.
    private static function part(string $value): string
    {
        return strlen($value).':'.$value;
    }
}
