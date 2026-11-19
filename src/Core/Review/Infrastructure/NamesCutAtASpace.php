<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\PathNotInCheckout;
use Intakt\Core\Review\Contract\PathOutsideCheckout;

// A word that is the start of a name with a space (docs/Release in "docs/Release Notes.md") must give no key, because the rest of that name is a word of its own and an edit of that file would not change the key.
final readonly class NamesCutAtASpace
{
    private const string FIRST_WORD = '/^(\S+)\s/u';
    private const string FIRST_WORD_IN_BYTES = '/^(\S+)\s/';

    /**
     * @param list<string> $words
     */
    public function anyIn(Checkout $checkout, array $words): bool
    {
        $firstWordsByDirectory = [];

        foreach (array_unique($words) as $word) {
            $cut = strrpos($word, '/');
            $directory = false === $cut ? '.' : substr($word, 0, $cut);
            $firstWords = $firstWordsByDirectory[$directory] ??= $this->firstWordsOfNamesWithASpaceIn($checkout, $directory);

            if (null === $firstWords || in_array(false === $cut ? $word : substr($word, $cut + 1), $firstWords, true)) {
                return true;
            }
        }

        return false;
    }

    // Null for a directory that cannot be listed, because it may hold such a name.
    /**
     * @return list<string>|null
     */
    private function firstWordsOfNamesWithASpaceIn(Checkout $checkout, string $directory): ?array
    {
        $real = $this->realPathOf($checkout, $directory);

        if (null === $real || !is_dir($real)) {
            return [];
        }

        $entries = scandir($real);

        return false === $entries ? null : array_values(array_filter(array_map($this->firstWordOf(...), $entries), static fn (?string $firstWord): bool => null !== $firstWord));
    }

    // A file name need not be UTF-8, so one that is not is split by bytes.
    private function firstWordOf(string $name): ?string
    {
        $matched = preg_match(self::FIRST_WORD, $name, $match);

        if (false === $matched) {
            $matched = preg_match(self::FIRST_WORD_IN_BYTES, $name, $match);
        }

        return 1 === $matched ? $match[1] : null;
    }

    private function realPathOf(Checkout $checkout, string $path): ?string
    {
        try {
            return $checkout->resolve($path);
        } catch (PathNotInCheckout|PathOutsideCheckout) {
            return null;
        }
    }
}
