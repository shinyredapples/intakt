<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

// The closing rule of the key: every place the text names a file of the checkout (its name in any case, with no letter or digit right before or after it) must lie inside a citation the reading resolved to a hashed file, else the finding gets no key. The reading only decides which files are hashed; this rule keeps the key safe for any citation form the reading does not know (Core/B.php::run(), file=B.php, @B.php, src/B.php:Intakt\Core\X), so no form needs a tokenizer case of its own.
final readonly class NamesOutsideCitations
{
    // A citation starts where a word starts (at the start, after white space, a backtick or an opening bracket, quote or asterisk) and ends after an optional line number with closing punctuation and white space, a backtick or the end, so a citation inside a longer word (Core/B.php, src/B.php:12/14) does not count.
    private const string CITATION_START = '(?<![^\s`("\'\[<{*])';
    private const string CITATION_END = '(?::\d+(?:[-:]\d+)?)?(?=[)"\'\]>},;:!?.*]*(?:\s|`|$))';
    private const string NAME_START = '(?<![\p{L}\p{N}])';
    private const string NAME_END = '(?![\p{L}\p{N}])';

    // True when a file's name lies outside every read citation, or when the text cannot be searched (not UTF-8), so its caller gives no key. The citations are those that resolved to a hashed file, as the reading read them, so a backslash in the text is read as `/` before they are looked up.
    /**
     * @param list<string> $citations
     */
    public function anyIn(string $text, CheckoutFileNames $fileNames, array $citations): bool
    {
        $cited = $this->placesOfCitations(str_replace('\\', '/', $text), $citations);

        if (null === $cited) {
            return true;
        }

        $lowerText = strtolower($text);
        $foldedText = $this->folded($text);

        foreach ($fileNames->namesOfFiles() as $name) {
            if (!str_contains($lowerText, strtolower($name)) && !str_contains($foldedText, $this->folded($name))) {
                continue;
            }

            $places = $this->placesOf('/'.self::NAME_START.preg_quote($name, '/').self::NAME_END.'/iu', $text);

            if (null === $places || array_any($places, static fn (array $place): bool => !self::isInside($place, $cited))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $citations
     *
     * @return list<array{int, int}>|null
     */
    private function placesOfCitations(string $text, array $citations): ?array
    {
        $places = [];

        foreach (array_unique($citations) as $citation) {
            $found = $this->placesOf('/'.self::CITATION_START.preg_quote($citation, '/').self::CITATION_END.'/u', $text);

            if (null === $found) {
                return null;
            }

            $places = [...$places, ...$found];
        }

        return $places;
    }

    // Folded for a quick check, so most names of the checkout skip the regular expression. Simple case folding, not lower case, because the caseless regular expression treats every letter of a fold set as one (ς and σ, ſ and s), and lower case keeps such letters apart, so the check would skip a name the expression finds. A name that is not UTF-8 is lowered by bytes, because mb_convert_case cannot read it.
    private function folded(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? mb_convert_case($text, MB_CASE_FOLD_SIMPLE, 'UTF-8') : strtolower($text);
    }

    // Byte offsets, because a name's place is compared with the citations' places found in the same bytes; null when the pattern or the text is not UTF-8, so the caller stays on the safe side.
    /**
     * @return list<array{int, int}>|null
     */
    private function placesOf(string $pattern, string $text): ?array
    {
        if (false === preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return array_map(static fn (array $match): array => [$match[1], $match[1] + strlen($match[0])], $matches[0]);
    }

    /**
     * @param array{int, int}       $place
     * @param list<array{int, int}> $cited
     */
    private static function isInside(array $place, array $cited): bool
    {
        return array_any($cited, static fn (array $citation): bool => $citation[0] <= $place[0] && $place[1] <= $citation[1]);
    }
}
