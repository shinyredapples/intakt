<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;
use Intakt\Core\Review\Domain\FindingKey;

// Null whenever a named file cannot be hashed for sure, because a key that stayed the same while a named file changed would reuse a verdict on code nobody judged; a finding without a key is judged again.
final readonly class FindingKeys
{
    // A run of backticks opens a span and only a run of the same length closes it, as in Markdown, so ``a`b.md`` is one span.
    private const string CODE_SPAN = '/(?<!`)(`+)(?!`)([^\n]+?)(?<!`)\1(?!`)/u';
    private const string FRAGMENT = '/^[^\/\p{Cc}]+$/u';
    private const string FILE_EXTENSION = '/\.[\p{L}\p{N}]+$/u';
    private const string WORD_EDGES = '/^[("\'\[<{*]+|[)"\'\]>},;:!?.*]+$/u';
    private const string CITATION = '/^(.+?)(?::\d+(?:[-:]\d+)?)?\.?$/u';
    private const string QUOTED_OR_SPACE = '/((?<!`)(`+)(?!`)[^\n]+?(?<!`)\2(?!`)|"[^"]*")|\s+/u';

    public function of(Finding $finding, string $checkoutRoot): ?FindingKey
    {
        $root = realpath($checkoutRoot);

        if (false === $root || !is_dir($root)) {
            return null;
        }

        $files = $this->namedFiles(new Checkout($root), $root, $finding);

        return null === $files || [] === $files ? null : FindingKey::of($this->normalized($finding->text), $files);
    }

    /**
     * @return list<array{string, string}>|null
     */
    private function namedFiles(Checkout $checkout, string $root, Finding $finding): ?array
    {
        $words = $this->wordsOf($finding->text);
        $citations = array_values(array_filter(array_unique([...$this->codeSpansOf($finding->text), ...$words]), $this->mayNameAFile(...)));

        $found = [
            $this->fileOfTheFileField($checkout, $finding->file),
            new NamesCutAtASpace()->anyIn($checkout, [...$words, ...$this->rawWordsOf($finding->text)]) ? false : [],
            ...array_map(fn (string $path): array|false => $this->fileAtPath($checkout, $path), $this->withDirectory($citations)),
            $this->filesWithNames($checkout, $root, $this->namesWithoutDirectory($checkout, $citations)),
        ];

        if (in_array(false, $found, true)) {
            return null;
        }

        return array_values(array_column(array_merge(...$found), null, 0));
    }

    // Each span read whole, so a cited path with a space or any other character stays one path.
    /**
     * @return list<string>
     */
    private function codeSpansOf(string $text): array
    {
        preg_match_all(self::CODE_SPAN, $text, $spans);

        return array_map(fn (string $span): string => $this->asPath($this->withoutLineNumber(trim($span))), $spans[2]);
    }

    /**
     * @return list<string>
     */
    private function wordsOf(string $text): array
    {
        return array_map(fn (string $word): string => $this->asPath($this->withoutLineNumber((string) preg_replace(self::WORD_EDGES, '', $word))), $this->rawWordsOf($text));
    }

    // Read with the spans taken out, so a word of a span with a space is never taken for a path of its own.
    /**
     * @return list<string>
     */
    private function rawWordsOf(string $text): array
    {
        $words = preg_split('/\s+/u', (string) preg_replace(self::CODE_SPAN, ' ', $text), flags: PREG_SPLIT_NO_EMPTY);

        return false === $words ? [] : $words;
    }

    private function withoutLineNumber(string $citation): string
    {
        return 1 === preg_match(self::CITATION, $citation, $match) ? $match[1] : $citation;
    }

    // Backslashes become slashes, because a reviewer may write a path the Windows way (src\Deep\B.php).
    private function asPath(string $citation): string
    {
        return str_replace('\\', '/', $citation);
    }

    private function mayNameAFile(string $candidate): bool
    {
        return [] === array_filter(explode('/', $candidate), static fn (string $segment): bool => 1 !== preg_match(self::FRAGMENT, $segment));
    }

    // The file field holds a path with no line number (the line has its own field), so it is taken as written and must be a file that can be hashed.
    /**
     * @return list<array{string, string}>|false
     */
    private function fileOfTheFileField(Checkout $checkout, ?string $file): array|false
    {
        if (null === $file) {
            return [];
        }

        $hashed = $this->hashOf($checkout, $file);

        return null === $hashed || false === $hashed ? false : [$hashed];
    }

    /**
     * @param list<string> $citations
     *
     * @return list<string>
     */
    private function withDirectory(array $citations): array
    {
        return array_values(array_filter($citations, static fn (string $citation): bool => str_contains($citation, '/')));
    }

    // A path with a directory and a file extension that names no regular file most likely names a file the key cannot hash.
    /**
     * @return list<array{string, string}>|false
     */
    private function fileAtPath(Checkout $checkout, string $path): array|false
    {
        $hashed = $this->hashOf($checkout, $path);

        if (null === $hashed) {
            return $this->hasFileExtension($path) ? false : [];
        }

        return false === $hashed ? false : [$hashed];
    }

    // A name without a directory counts when it has a file extension or a file of the checkout root carries it, because a root file such as Makefile has no extension.
    /**
     * @param list<string> $citations
     *
     * @return list<string>
     */
    private function namesWithoutDirectory(Checkout $checkout, array $citations): array
    {
        return array_values(array_filter(
            $citations,
            fn (string $citation): bool => !str_contains($citation, '/') && ($this->hasFileExtension($citation) || null !== $this->hashOf($checkout, $citation)),
        ));
    }

    /**
     * @param list<string> $names
     *
     * @return list<array{string, string}>|false
     */
    private function filesWithNames(Checkout $checkout, string $root, array $names): array|false
    {
        if ([] === $names) {
            return [];
        }

        $fileNames = CheckoutFileNames::in($root);

        if (!$fileNames instanceof CheckoutFileNames) {
            return false;
        }

        $found = array_map(fn (string $name): array|false => $this->fileCarrying($checkout, $fileNames->pathsNamed($name)), $names);

        return in_array(false, $found, true) ? false : array_merge(...$found);
    }

    // Several files with the name give no key, because the name alone cannot tell which of them the finding means.
    /**
     * @param list<string> $paths
     *
     * @return list<array{string, string}>|false
     */
    private function fileCarrying(Checkout $checkout, array $paths): array|false
    {
        if ([] === $paths) {
            return [];
        }

        $hashed = 1 === count($paths) ? $this->hashOf($checkout, $paths[0]) : false;

        return null === $hashed || false === $hashed ? false : [$hashed];
    }

    // Null when nothing regular is at the path, false when a file is there that cannot be hashed for sure (unreadable, or a link out of the checkout).
    /**
     * @return array{string, string}|false|null
     */
    private function hashOf(Checkout $checkout, string $path): array|false|null
    {
        try {
            $real = $checkout->resolve($path);
        } catch (PathNotInCheckout) {
            return null;
        } catch (PathOutsideCheckout) {
            return false;
        }

        if (!is_file($real)) {
            return null;
        }

        $hash = is_readable($real) ? hash_file(FindingKey::ALGORITHM, $real) : false;

        return false === $hash ? false : [$checkout->relative($real), $hash];
    }

    private function hasFileExtension(string $candidate): bool
    {
        return 1 === preg_match(self::FILE_EXTENSION, $candidate);
    }

    // Whitespace runs become one space outside backtick spans and double quotes, so a reflowed finding keeps its key while a quoted value keeps its spacing; single quotes do not keep spacing, because a single quote is also an apostrophe ("the change's lock") and cannot reliably mark a value.
    private function normalized(string $text): string
    {
        $normalized = preg_replace_callback(self::QUOTED_OR_SPACE, static fn (array $match): string => '' === ($match[1] ?? '') ? ' ' : $match[1], $text);

        return trim($normalized ?? $text);
    }
}
