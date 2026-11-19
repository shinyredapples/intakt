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

    // Each file as its checkout-relative path and content hash, sorted here, so the order a finding names its files in does not matter.
    /**
     * @param list<array{string, string}> $files
     */
    public static function of(string $text, array $files): self
    {
        usort($files, static fn (array $one, array $other): int => strcmp($one[0], $other[0]));
        $parts = [self::part($text)];

        foreach ($files as [$path, $hash]) {
            $parts[] = self::part($path).self::part($hash);
        }

        return new self(hash(self::ALGORITHM, implode('', $parts)));
    }

    // Length-prefixed, because a path or the text may hold any separator, and two different sets of files must never give one key.
    private static function part(string $value): string
    {
        return strlen($value).':'.$value;
    }
}
