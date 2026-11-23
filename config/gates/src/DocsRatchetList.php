<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class DocsRatchetList
{
    public const string PATH = 'ratchet/docs.json';

    /**
     * @param array<string, list<string>> $paragraphs paragraph keys by page
     * @param array<string, int>          $pages      word ceiling by page
     * @param list<string>                $summaries  pages excused from the "In short" rule
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

        if (!is_array($data) || !self::isWellFormed($data)) {
            return null;
        }

        /** @var array{paragraphs: array<string, list<string>>, pages: array<string, int>, summaries: list<string>} $data */
        return new self($data['paragraphs'], $data['pages'], $data['summaries']);
    }

    public function excusesParagraph(string $page, string $key): bool
    {
        return in_array($key, $this->paragraphs[$page] ?? [], true);
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
        $findings = [];

        foreach ($this->paragraphs as $page => $keys) {
            $added = count(array_diff($keys, $base->paragraphs[$page] ?? []));

            if ($added > 0) {
                $findings[] = self::PATH.': '.$added.' paragraph'.(1 === $added ? '' : 's').' of '.$page.' added to the list; the list only shrinks, so shorten the paragraph instead';
            }
        }

        foreach ($this->pages as $page => $ceiling) {
            $before = $base->pages[$page] ?? null;

            if (null === $before || $ceiling > $before) {
                $findings[] = self::PATH.': the page ceiling of '.$page.' is '.(null === $before ? 'new' : 'raised from '.$before.' to '.$ceiling).'; the list only shrinks, so shorten the page instead';
            }
        }

        foreach (array_diff($this->summaries, $base->summaries) as $page) {
            $findings[] = self::PATH.': '.$page.' added to the pages excused from "In short"; the list only shrinks, so write the summary instead';
        }

        return $findings;
    }

    /**
     * @param array<mixed> $data
     */
    private static function isWellFormed(array $data): bool
    {
        return isset($data['paragraphs'], $data['pages'], $data['summaries'])
            && is_array($data['paragraphs']) && is_array($data['pages']) && is_array($data['summaries'])
            && array_all($data['paragraphs'], static fn (mixed $keys): bool => is_array($keys) && array_is_list($keys) && array_all($keys, static fn (mixed $key): bool => is_string($key)))
            && array_all($data['pages'], static fn (mixed $ceiling): bool => is_int($ceiling))
            && array_is_list($data['summaries']) && array_all($data['summaries'], static fn (mixed $page): bool => is_string($page));
    }
}
