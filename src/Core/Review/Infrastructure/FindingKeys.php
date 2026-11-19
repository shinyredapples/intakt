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
    private const string CODE_SPAN = '/`([^`\n]+)`/u';
    private const string FRAGMENT = '/^[^\/\p{Cc}]+$/u';
    private const string FILE_EXTENSION = '/\.[\p{L}\p{N}]+$/u';
    private const string WORD_EDGES = '/^[("\'\[<{*]+|[)"\'\]>},;:!?.*]+$/u';
    private const string CITATION = '/^(.+?)(?::\d+(?:[-:]\d+)?)?\.?$/u';
    private const string QUOTED_OR_SPACE = '/(`[^`]*`|"[^"]*")|\s+/u';

    public function of(Finding $finding, string $checkoutRoot): ?FindingKey
    {
        if (!is_dir($checkoutRoot)) {
            return null;
        }

        $files = $this->namedFiles(new Checkout($checkoutRoot), $this->candidatesIn($finding));

        return null === $files || [] === $files ? null : FindingKey::of($this->normalized($finding->text), $files);
    }

    /**
     * @return list<string>
     */
    private function candidatesIn(Finding $finding): array
    {
        $candidates = [...$this->codeSpansOf($finding->text), ...$this->wordsOf($finding->text)];

        if (null !== $finding->file) {
            $candidates[] = $this->withoutLineNumber($finding->file);
        }

        return array_values(array_filter(array_unique($candidates), $this->mayNameAFile(...)));
    }

    // Each span read whole, so a cited path with a space or any other character stays one path.
    /**
     * @return list<string>
     */
    private function codeSpansOf(string $text): array
    {
        preg_match_all(self::CODE_SPAN, $text, $spans);

        return array_map(fn (string $span): string => $this->withoutLineNumber(trim($span)), $spans[1]);
    }

    // Read with the spans taken out, so a word of a span with a space is never taken for a path of its own.
    /**
     * @return list<string>
     */
    private function wordsOf(string $text): array
    {
        $words = preg_split('/\s+/u', (string) preg_replace(self::CODE_SPAN, ' ', $text), flags: PREG_SPLIT_NO_EMPTY);

        return array_map(fn (string $word): string => $this->withoutLineNumber((string) preg_replace(self::WORD_EDGES, '', $word)), false === $words ? [] : $words);
    }

    private function withoutLineNumber(string $citation): string
    {
        return 1 === preg_match(self::CITATION, $citation, $match) ? $match[1] : $citation;
    }

    private function mayNameAFile(string $candidate): bool
    {
        $segments = explode('/', $candidate);

        return [] === array_filter($segments, static fn (string $segment): bool => 1 !== preg_match(self::FRAGMENT, $segment))
            && (count($segments) > 1 || 1 === preg_match(self::FILE_EXTENSION, $candidate));
    }

    /**
     * @param list<string> $candidates
     *
     * @return list<array{string, string}>|null
     */
    private function namedFiles(Checkout $checkout, array $candidates): ?array
    {
        $files = [];

        foreach ($candidates as $candidate) {
            $file = $this->namedFile($checkout, $candidate);

            if (false === $file) {
                return null;
            }

            if (null !== $file) {
                $files[$file[0]] = $file;
            }
        }

        return array_values($files);
    }

    // False when the candidate may name a file the key cannot hash, null when it names no file.
    /**
     * @return array{string, string}|false|null
     */
    private function namedFile(Checkout $checkout, string $candidate): array|false|null
    {
        $real = $this->realPathOf($checkout, $candidate);

        if (null !== $real && is_file($real)) {
            $hash = is_readable($real) ? hash_file(FindingKey::ALGORITHM, $real) : false;

            return false === $hash ? false : [$checkout->relative($real), $hash];
        }

        return $this->mayNameAFileNotHashed($checkout, $candidate, $real) ? false : null;
    }

    private function mayNameAFileNotHashed(Checkout $checkout, string $candidate, ?string $real): bool
    {
        return $this->isPath($candidate) || (null === $real && $this->directoryIsInCheckout($checkout, $candidate));
    }

    private function realPathOf(Checkout $checkout, string $path): ?string
    {
        try {
            return $checkout->resolve($path);
        } catch (PathNotInCheckout|PathOutsideCheckout) {
            return null;
        }
    }

    // A path with a directory and a file extension that names no regular file of the checkout, because it most likely names a file the key cannot hash.
    private function isPath(string $candidate): bool
    {
        return str_contains($candidate, '/') && 1 === preg_match(self::FILE_EXTENSION, $candidate);
    }

    // A missing path in a directory of the checkout may be the first part of a path with a space ("docs/Release Notes.md" in plain text), so it gives no key instead of being skipped.
    private function directoryIsInCheckout(Checkout $checkout, string $candidate): bool
    {
        if (!str_contains($candidate, '/')) {
            return false;
        }

        $directory = $this->realPathOf($checkout, dirname($candidate));

        return null !== $directory && is_dir($directory);
    }

    // Whitespace runs become one space outside backtick spans and double quotes, so a reflowed finding keeps its key while a quoted value keeps its spacing; single quotes do not keep spacing, because a single quote is also an apostrophe ("the change's lock") and cannot reliably mark a value.
    private function normalized(string $text): string
    {
        $normalized = preg_replace_callback(self::QUOTED_OR_SPACE, static fn (array $match): string => '' === ($match[1] ?? '') ? ' ' : $match[1], $text);

        return trim($normalized ?? $text);
    }
}
