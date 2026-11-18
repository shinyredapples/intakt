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

    // Matched as Unicode, so a name such as `docs/Überblick.md` is one fragment and not cut at its first letter outside ASCII.
    private const string UNICODE = 'u';

    private const string CODE_SPAN = '~`([^`]+)`~';

    // Dropped from a code span, because a citation puts a line or a line range and the full stop of its sentence behind the path, and the file is named without them.
    private const string LINE_AND_FULL_STOP = '~(?::\d+(?:-\d+)?)?\.?$~';

    // Kept as written by the key, because spacing inside one is part of a value.
    private const string QUOTED = '~(`[^`]*`|"[^"]*")~';

    // Only fragments with a slash and a file extension count as certain, because prose never reads like that, so such a path that is missing or outside the checkout may refuse the key.
    /**
     * @return list<string>
     */
    public function namedPaths(Finding $finding): array
    {
        $inText = array_filter($this->fragmentsOf($finding->text), $this->isCertainlyAPath(...));

        return array_values(array_unique([...(null === $finding->file ? [] : [$finding->file]), ...$inText]));
    }

    // Every other word of the text, because a file at the root is cited by its bare name (`Makefile`, `LICENSE`, `README.md`) and reads like prose (`and/or`, `8.5/8.6`, a route, `e.g`), and every inline code span as a whole, because a path with a space or another character no fragment holds (`docs/Release Notes.md`) is otherwise cut into pieces that are no files. Each counts only where the checkout holds a regular file under that path, so a wrong guess can only add a real file to the key, which errs toward judging again.
    /**
     * @return list<string>
     */
    public function possiblePaths(Finding $finding): array
    {
        $named = $this->namedPaths($finding);
        $words = array_filter($this->fragmentsOf($finding->text), fn (string $fragment): bool => !$this->isCertainlyAPath($fragment));
        $possible = array_filter([...$words, ...$this->codeSpansOf($finding->text)], static fn (string $path): bool => !in_array($path, $named, true));

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
        // Byte by byte for a text that is no valid UTF-8, which the Unicode match refuses as a whole, so its paths are still read.
        if (false === preg_match_all(self::FRAGMENT.self::UNICODE, $text, $matches)) {
            preg_match_all(self::FRAGMENT, $text, $matches);
        }

        // Trimmed, because a path that ends a sentence carries its full stop.
        return array_values(array_filter(array_map(static fn (string $fragment): string => rtrim($fragment, '.'), $matches[0]), static fn (string $fragment): bool => '' !== $fragment));
    }

    /**
     * @return list<string>
     */
    private function codeSpansOf(string $text): array
    {
        preg_match_all(self::CODE_SPAN, $text, $matches);
        $spans = array_map(static fn (string $span): string => (string) preg_replace(self::LINE_AND_FULL_STOP, '', trim($span)), $matches[1]);

        return array_values(array_filter($spans, static fn (string $span): bool => '' !== $span));
    }

    private function isCertainlyAPath(string $fragment): bool
    {
        $hasExtension = preg_match(self::FILE_EXTENSION.self::UNICODE, $fragment);

        return str_contains($fragment, '/') && 1 === (false === $hasExtension ? preg_match(self::FILE_EXTENSION, $fragment) : $hasExtension);
    }

    // Spacing ignored, because a reviewer rewording the same finding on the next head changes only that, but kept inside backticks and double quotes, because `Admin User` and `Admin  User` can be two values; case is kept, because `Admin` and `admin` can be two values.
    private function normalized(string $text): string
    {
        $parts = preg_split(self::QUOTED, $text, flags: PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts) {
            return $text;
        }

        // Every second part is a quote, because the split keeps each one between the texts around it.
        $spaced = array_map(static fn (string $part, int $at): string => 0 === $at % 2 ? (string) preg_replace('/\s+/', ' ', $part) : $part, $parts, array_keys($parts));

        return trim(implode('', $spaced), ' ');
    }
}
