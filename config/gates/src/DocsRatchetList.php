<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;

final readonly class DocsRatchetList
{
    public const string PATH = 'ratchet/docs.json';
    private const array SECTIONS = ['paragraphs', 'pages', 'summaries'];
    private const string KEY = '/^[0-9a-f]{16}$/';

    /**
     * @param array<string, array<string, int>> $paragraphs
     * @param array<string, int>                $pages
     * @param array<string, int>                $summaries
     */
    private function __construct(
        public array $paragraphs,
        public array $pages,
        public array $summaries,
    ) {
    }

    public static function parsed(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DocsListInvalid('is not valid JSON: '.$exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        if (!is_array($decoded) || self::SECTIONS !== array_keys($decoded)) {
            throw new DocsListInvalid('must be an object of exactly "paragraphs", "pages" and "summaries", in that order');
        }

        return new self(self::paragraphsIn($decoded['paragraphs']), self::countsIn('pages', $decoded['pages']), self::countsIn('summaries', $decoded['summaries']));
    }

    public function paragraphCount(string $page, string $key): int
    {
        return $this->paragraphs[$page][$key] ?? 0;
    }

    /**
     * @return array<string, array<string, int>>
     */
    private static function paragraphsIn(mixed $section): array
    {
        if (!is_array($section)) {
            throw new DocsListInvalid('"paragraphs" must map each page to its keys');
        }

        $paragraphs = [];

        foreach ($section as $page => $keys) {
            $paragraphs[(string) $page] = self::keysOf((string) $page, $keys);
        }

        return $paragraphs;
    }

    /**
     * @return array<string, int>
     */
    private static function keysOf(string $page, mixed $keys): array
    {
        if (!is_array($keys) || [] === $keys) {
            throw new DocsListInvalid('"paragraphs" of '.$page.' must map at least one key to a count');
        }

        foreach (array_keys($keys) as $key) {
            if (1 !== preg_match(self::KEY, (string) $key)) {
                throw new DocsListInvalid('"paragraphs" of '.$page.' holds '.$key.', which is no key of 16 lowercase hex characters');
            }
        }

        return self::countsIn('paragraphs of '.$page, $keys);
    }

    /**
     * @return array<string, int>
     */
    private static function countsIn(string $section, mixed $values): array
    {
        if (!is_array($values)) {
            throw new DocsListInvalid('"'.$section.'" must be an object');
        }

        $counts = [];

        foreach ($values as $name => $count) {
            if (!is_int($count) || $count < 1) {
                throw new DocsListInvalid('"'.$section.'" gives '.$name.' no whole number of at least 1');
            }

            $counts[(string) $name] = $count;
        }

        return $counts;
    }
}
