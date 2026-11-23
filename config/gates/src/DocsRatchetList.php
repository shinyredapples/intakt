<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsRatchetList
{
    public const string PATH = 'ratchet/docs.json';

    /**
     * @param array<string, list<string>> $paragraphs
     * @param array<string, int>          $pages
     * @param list<string>                $summaries
     */
    public function __construct(
        public array $paragraphs,
        public array $pages,
        public array $summaries,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], []);
    }

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            return null;
        }

        $paragraphs = self::paragraphsOf($data['paragraphs'] ?? null);
        $pages = self::ceilingsOf($data['pages'] ?? null);
        $summaries = self::stringsOf($data['summaries'] ?? null);

        return in_array(null, [$paragraphs, $pages, $summaries], true) ? null : new self($paragraphs, $pages, $summaries);
    }

    // Because a key listed once excuses one paragraph: a copy of a listed wall is a new wall.
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
                $findings[] = self::PATH.': '.$added.' paragraph'.(1 === $added ? '' : 's').' of '.$page.' added to the list; the list only shrinks, so shorten the paragraph instead';
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
            $before = $base->pages[$page] ?? null;

            if (null === $before || $ceiling > $before) {
                $findings[] = self::PATH.': the page ceiling of '.$page.' is '.(null === $before ? 'new' : 'raised from '.$before.' to '.$ceiling).'; the list only shrinks, so shorten the page instead';
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
            static fn (string $page): string => self::PATH.': '.$page.' added to the pages excused from "In short"; the list only shrinks, so write the summary instead',
            array_diff($this->summaries, $base->summaries),
        ));
    }

    /**
     * @return array<string, list<string>>|null
     */
    private static function paragraphsOf(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $paragraphs = [];

        foreach ($value as $page => $keys) {
            $strings = self::stringsOf($keys);

            if (null === $strings) {
                return null;
            }

            $paragraphs[(string) $page] = $strings;
        }

        return $paragraphs;
    }

    /**
     * @return array<string, int>|null
     */
    private static function ceilingsOf(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $ceilings = [];

        foreach ($value as $page => $ceiling) {
            if (!is_int($ceiling)) {
                return null;
            }

            $ceilings[(string) $page] = $ceiling;
        }

        return $ceilings;
    }

    /**
     * @return list<string>|null
     */
    private static function stringsOf(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $strings = [];

        foreach ($value as $string) {
            if (!is_string($string)) {
                return null;
            }

            $strings[] = $string;
        }

        return $strings;
    }
}
