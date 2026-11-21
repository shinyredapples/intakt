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
    private const string NAME_END = '/[^\p{L}\p{N}]/u';
    private const string CITATION = '/^(.+?)(?::\d+(?:[-:]\d+)?)?\.?$/u';
    private const string QUOTED_OR_SPACE = '/((?<!`)(`+)(?!`)[^\n]+?(?<!`)\2(?!`)|"[^"]*")|\s+/u';

    public function __construct(
        private NamesCutAtASpace $namesCutAtASpace = new NamesCutAtASpace(),
        private NamesOutsideCitations $namesOutsideCitations = new NamesOutsideCitations(),
    ) {
    }

    public function of(Finding $finding, string $checkoutRoot): ?FindingKey
    {
        $root = realpath($checkoutRoot);

        if (false === $root || !is_dir($root)) {
            return null;
        }

        $fileNames = CheckoutFileNames::in($root);

        if (!$fileNames instanceof CheckoutFileNames) {
            return null;
        }

        $files = $this->namedFiles(new Checkout($root), $fileNames, $finding);

        return null === $files || [] === $files ? null : FindingKey::of($this->normalized($finding->text), $files);
    }

    // Read like the key reads them but without the checkout, because the escalation prompt matches them against the diff, where a candidate that names no file simply matches nothing.
    /**
     * @return list<string>
     */
    public function citedIn(Finding $finding): array
    {
        // A plain word counts only when it has an extension or a directory, so "check" in a sentence never matches bin/check.
        $words = array_filter($this->wordsOf($finding->text), fn (string $word): bool => str_contains($word, '/') || 1 === preg_match(self::FILE_EXTENSION, $word));
        $citations = array_filter([...$this->codeSpansOf($finding->text), ...$words], $this->mayNameAFile(...));

        return array_values(array_unique(null === $finding->file ? $citations : [$finding->file, ...$citations]));
    }

    /**
     * @return list<array{string, string}>|null
     */
    private function namedFiles(Checkout $checkout, CheckoutFileNames $fileNames, Finding $finding): ?array
    {
        $words = $this->wordsOf($finding->text);
        $citations = array_values(array_filter(array_unique([...$this->codeSpansOf($finding->text), ...$words]), $this->mayNameAFile(...)));

        $withDirectory = $this->withDirectory($citations);
        $withoutDirectory = $this->withoutDirectory($citations);
        $atPaths = array_map(fn (string $path): array|false => $this->fileAtPath($checkout, $path), $withDirectory);
        $carrying = array_map(fn (string $name): array|false => $this->fileCarrying($checkout, $fileNames, $name), $withoutDirectory);

        $found = [
            $this->fileOfTheFileField($checkout, $finding->file),
            $this->namesCutAtASpace->anyIn($checkout, $fileNames, [...$words, ...$this->rawWordsOf($finding->text)]) ? false : [],
            ...$atPaths,
            ...$carrying,
        ];

        if (in_array(false, $found, true)) {
            return null;
        }

        $read = [...$this->citationsOfHashedFiles($withDirectory, $atPaths), ...$this->citationsOfHashedFiles($withoutDirectory, $carrying)];

        if ($this->namesOutsideCitations->anyIn($finding->text, $fileNames, $read)) {
            return null;
        }

        return array_values(array_column(array_merge(...$found), null, 0));
    }

    // The file field is one citation by itself, read whole, so only the text is checked for names outside citations.
    /**
     * @param list<string>                            $citations
     * @param list<list<array{string, string}>|false> $files
     *
     * @return list<string>
     */
    private function citationsOfHashedFiles(array $citations, array $files): array
    {
        return array_values(array_filter($citations, static fn (string $citation, int $index): bool => is_array($files[$index] ?? null) && [] !== $files[$index], ARRAY_FILTER_USE_BOTH));
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

    // A path that names no regular file gives no key when it has a file extension or its parent is in the checkout, because it most likely cites a file there in a form the key cannot read (src/B.php::run(), src/B.php#L12); only a word pointing nowhere (and/or) or an existing directory is skipped.
    /**
     * @return list<array{string, string}>|false
     */
    private function fileAtPath(Checkout $checkout, string $path): array|false
    {
        $hashed = $this->hashOf($checkout, $path);

        if (null !== $hashed) {
            return false === $hashed ? false : [$hashed];
        }

        if ($this->hasFileExtension($path)) {
            return false;
        }

        return true === $this->isInCheckout($checkout, $path) || false === $this->isInCheckout($checkout, dirname($path)) ? [] : false;
    }

    // Null for a place that may not be looked at (a link out of the checkout), so its caller can stay on the safe side.
    private function isInCheckout(Checkout $checkout, string $path): ?bool
    {
        try {
            $checkout->resolve($path);
        } catch (PathNotInCheckout) {
            return false;
        } catch (PathOutsideCheckout) {
            return null;
        }

        return true;
    }

    /**
     * @param list<string> $citations
     *
     * @return list<string>
     */
    private function withoutDirectory(array $citations): array
    {
        return array_values(array_filter($citations, static fn (string $citation): bool => !str_contains($citation, '/')));
    }

    // A name without a directory stands for every file of the checkout that carries it: several give no key, because the name alone cannot tell which of them the finding means.
    /**
     * @return list<array{string, string}>|false
     */
    private function fileCarrying(Checkout $checkout, CheckoutFileNames $fileNames, string $name): array|false
    {
        $paths = $fileNames->pathsNamed($name);

        if ([] === $paths) {
            return $this->startsWithAFileName($fileNames, $name) ? false : [];
        }

        $hashed = 1 === count($paths) ? $this->hashOf($checkout, $paths[0]) : false;

        return null === $hashed || false === $hashed ? false : [$hashed];
    }

    // A name no file carries that starts with a file's name and goes on with a character that is no letter or digit (B.php::run(), B.php#L12) most likely cites that file in a form the key cannot read.
    private function startsWithAFileName(CheckoutFileNames $fileNames, string $name): bool
    {
        preg_match_all(self::NAME_END, $name, $ends, PREG_OFFSET_CAPTURE);

        return array_any($ends[0], static fn (array $end): bool => $end[1] > 0 && [] !== $fileNames->pathsNamed(substr($name, 0, $end[1])));
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
