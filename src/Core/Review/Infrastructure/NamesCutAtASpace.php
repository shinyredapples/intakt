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

    // A word without a directory may name a file anywhere, so it is checked against the names of every directory of the checkout.
    /**
     * @param list<string> $words
     */
    public function anyIn(Checkout $checkout, CheckoutFileNames $fileNames, array $words): bool
    {
        $firstWordsAnywhere = null;
        $firstWordsByDirectory = [];

        foreach (array_unique($words) as $word) {
            if (!str_contains($word, '/')) {
                $firstWords = $firstWordsAnywhere ??= $this->firstWordsOf($fileNames->namesOfFilesAndDirectories());
            } else {
                [$directory, $name] = $this->directoryAndNameOf($word);
                $firstWords = $firstWordsByDirectory[$directory] ??= $this->firstWordsOfNamesWithASpaceIn($checkout, $directory);
                $word = $name;
            }

            if (null === $firstWords || isset($firstWords[$word])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, true>
     */
    private function firstWordsOf(array $names): array
    {
        $firstWords = [];

        foreach ($names as $name) {
            $firstWord = $this->firstWordOf($name);

            if (null !== $firstWord) {
                $firstWords[$firstWord] = true;
            }
        }

        return $firstWords;
    }

    /**
     * @return array{string, string}
     */
    private function directoryAndNameOf(string $word): array
    {
        $cut = (int) strrpos($word, '/');

        return [substr($word, 0, $cut), substr($word, $cut + 1)];
    }

    // Null for a directory that cannot be listed, because it may hold such a name.
    /**
     * @return array<string, true>|null
     */
    private function firstWordsOfNamesWithASpaceIn(Checkout $checkout, string $directory): ?array
    {
        $real = $this->realPathOf($checkout, $directory);

        if (null === $real || !is_dir($real)) {
            return [];
        }

        $entries = is_readable($real) ? scandir($real) : false;

        return false === $entries ? null : $this->firstWordsOf($entries);
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
