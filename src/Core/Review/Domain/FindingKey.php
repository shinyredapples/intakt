<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The key of a finding is its text and the content of every file it names, so a verdict on it can stand only while that code is unchanged; a rule, not a service.
#[Exclude]
final readonly class FindingKey
{
    // A slash between plain name characters, so `Money::from()`, a sentence or a web address names no path; a leading slash is kept, so an absolute path is named and refused.
    private const string PATH_IN_TEXT = '~(?<![\w./-])/?[\w.-]+(?:/[\w.-]+)+~';

    /**
     * @return list<string>
     */
    public function namedPaths(Finding $finding): array
    {
        preg_match_all(self::PATH_IN_TEXT, $finding->text, $matches);
        // Trimmed, because a path that ends a sentence carries its full stop.
        $inText = array_map(static fn (string $path): string => rtrim($path, '.'), $matches[0]);

        return array_values(array_unique([...(null === $finding->file ? [] : [$finding->file]), ...$inText]));
    }

    // Null without a file, because a key on the text alone would stand for any code; the parts are JSON encoded, so no path or text can shift into its neighbor.
    /**
     * @param array<string, string> $fileHashes the SHA-256 of each named file's content, by path
     */
    public function of(Finding $finding, array $fileHashes): ?string
    {
        if ([] === $fileHashes) {
            return null;
        }

        $paths = array_map(strval(...), array_keys($fileHashes));
        sort($paths, SORT_STRING);
        $files = array_map(static fn (string $path): array => [$path, $fileHashes[$path]], $paths);

        return hash('sha256', json_encode([$this->normalized($finding->text), $files], JSON_THROW_ON_ERROR));
    }

    // Case and spacing ignored, because a reviewer rewording the same finding on the next head changes only those.
    private function normalized(string $text): string
    {
        return implode(' ', preg_split('/\s+/', mb_strtolower(trim($text)), flags: PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
