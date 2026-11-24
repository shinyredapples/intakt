<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsRatchetList
{
    public const string PATH = 'ratchet/docs.json';

    private const string KEY = '/^[0-9a-f]{16}$/';

    /**
     * @param array<string, list<string>> $paragraphs
     * @param array<string, int>          $pages
     * @param list<string>                $summaries
     */
    private function __construct(
        public array $paragraphs,
        public array $pages,
        public array $summaries,
    ) {
    }

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);

        if (!is_array($data) || [] !== array_diff(array_keys($data), ['paragraphs', 'pages', 'summaries'])) {
            return null;
        }

        $paragraphs = self::paragraphsOf($data['paragraphs'] ?? null);
        $pages = self::ceilingsOf($data['pages'] ?? null);
        $summaries = self::pathsOf($data['summaries'] ?? null);

        return in_array(null, [$paragraphs, $pages, $summaries], true) ? null : new self($paragraphs, $pages, $summaries);
    }

    // Because a key listed once excuses one paragraph, a copy of a listed paragraph on the same page is a new one.
    public function allowanceOf(string $page, string $key): int
    {
        return count(array_keys($this->paragraphs[$page] ?? [], $key, true));
    }

    public function ceilingOf(string $page): ?int
    {
        return $this->pages[$page] ?? null;
    }

    public function excusesSummary(string $page): bool
    {
        return in_array($page, $this->summaries, true);
    }

    /**
     * @return list<string>
     */
    public function growthOver(self $base): array
    {
        return [...$this->addedParagraphs($base), ...$this->raisedCeilings($base), ...$this->addedExcuses($base)];
    }

    /**
     * @return array<string, list<string>>|null
     */
    private static function paragraphsOf(mixed $value): ?array
    {
        if (!is_array($value) || array_is_list($value) && [] !== $value) {
            return null;
        }

        $paragraphs = [];

        foreach ($value as $page => $keys) {
            $listed = self::stringsOf($keys);

            if (null === $listed || [] === $listed || array_any($listed, static fn (string $key): bool => 1 !== preg_match(self::KEY, $key))) {
                return null;
            }

            $paragraphs[(string) $page] = $listed;
        }

        return $paragraphs;
    }

    /**
     * @return array<string, int>|null
     */
    private static function ceilingsOf(mixed $value): ?array
    {
        if (!is_array($value) || array_is_list($value) && [] !== $value) {
            return null;
        }

        $ceilings = [];

        foreach ($value as $page => $ceiling) {
            if (!is_int($ceiling) || $ceiling <= DocsLimits::PAGE_WORDS) {
                return null;
            }

            $ceilings[(string) $page] = $ceiling;
        }

        return $ceilings;
    }

    /**
     * @return list<string>|null
     */
    private static function pathsOf(mixed $value): ?array
    {
        $paths = self::stringsOf($value);

        return null === $paths || count(array_unique($paths)) !== count($paths) ? null : $paths;
    }

    /**
     * @return list<string>|null
     */
    private static function stringsOf(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $strings = array_values(array_filter($value, is_string(...)));

        return count($strings) === count($value) ? $strings : null;
    }

    /**
     * @return list<string>
     */
    private function addedParagraphs(self $base): array
    {
        $findings = [];

        foreach ($this->paragraphs as $page => $keys) {
            $added = 0;

            foreach (array_unique($keys) as $key) {
                $added += max(0, $this->allowanceOf($page, $key) - $base->allowanceOf($page, $key));
            }

            if ($added > 0) {
                $findings[] = self::PATH.': '.$added.' paragraph key(s) of '.$page.' added; the list only shrinks, so keep each paragraph within '.DocsLimits::PARAGRAPH_WORDS.' words instead';
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function raisedCeilings(self $base): array
    {
        $findings = [];

        foreach ($this->pages as $page => $ceiling) {
            $before = $base->ceilingOf($page);

            if (null === $before || $ceiling > $before) {
                $findings[] = self::PATH.': the page ceiling of '.$page.' is '.(null === $before ? 'new' : 'raised from '.$before.' to '.$ceiling).'; the list only shrinks, so keep the page within '.DocsLimits::PAGE_WORDS.' words instead';
            }
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function addedExcuses(self $base): array
    {
        return array_values(array_map(
            static fn (string $page): string => self::PATH.': '.$page.' added to the pages excused from the "In short" rule; the list only shrinks, so open the page with an "In short" section of at most '.DocsLimits::SUMMARY_WORDS.' words instead',
            array_diff($this->summaries, $base->summaries),
        ));
    }
}
