<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The key of a finding is its text and the content of every file it names, so a verdict on it can stand only while that code is unchanged; a rule, not a service.
#[Exclude]
final readonly class FindingKey
{
    // A run of name characters and slashes, so `Money::from()`, a sentence or a web address splits into fragments that are each read on their own; a leading slash is kept, so an absolute path is seen as one.
    private const string FRAGMENT = '~(?<![\w./-])/?[\w.-]+(?:/[\w.-]+)*~';

    // A letter after the last dot, so a version such as 8.6 is no file extension.
    private const string FILE_EXTENSION = '~\.[A-Za-z]\w*$~';

    // The finding's file and every fragment with a slash and a file extension, which prose never reads like; such a path that is missing or outside the checkout refuses the key.
    /**
     * @return list<string>
     */
    public function namedPaths(Finding $finding): array
    {
        $inText = array_filter($this->fragmentsOf($finding->text), $this->isCertainlyAPath(...));

        return array_values(array_unique([...(null === $finding->file ? [] : [$finding->file]), ...$inText]));
    }

    // Fragments that read as prose too (`and/or`, `8.5/8.6`, a route, `e.g`) or name a file without a directory (`README.md`), so they count only where the checkout holds a regular file under that path.
    /**
     * @return list<string>
     */
    public function possiblePaths(Finding $finding): array
    {
        $named = $this->namedPaths($finding);
        $possible = array_filter(
            $this->fragmentsOf($finding->text),
            fn (string $fragment): bool => !in_array($fragment, $named, true) && !$this->isCertainlyAPath($fragment) && (str_contains($fragment, '/') || 1 === preg_match(self::FILE_EXTENSION, $fragment)),
        );

        return array_values(array_unique($possible));
    }

    // Null without a file, because a key on the text alone would stand for any code; the parts are JSON encoded, so no path or text can shift into its neighbor. The hashes are each named file's SHA-256 by path.
    /**
     * @param array<string, string> $fileHashes
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

    /**
     * @return list<string>
     */
    private function fragmentsOf(string $text): array
    {
        preg_match_all(self::FRAGMENT, $text, $matches);

        // Trimmed, because a path that ends a sentence carries its full stop.
        return array_values(array_filter(array_map(static fn (string $fragment): string => rtrim($fragment, '.'), $matches[0]), static fn (string $fragment): bool => '' !== $fragment));
    }

    private function isCertainlyAPath(string $fragment): bool
    {
        return str_contains($fragment, '/') && 1 === preg_match(self::FILE_EXTENSION, $fragment);
    }

    // Spacing ignored, because a reviewer rewording the same finding on the next head changes only that; case is kept, because `Admin` and `admin` can be two values.
    private function normalized(string $text): string
    {
        $words = preg_split('/\s+/', $text, flags: PREG_SPLIT_NO_EMPTY);

        return false === $words ? $text : implode(' ', $words);
    }
}
