<?php

declare(strict_types=1);

namespace Intakt\Gates;

use JsonException;

final readonly class DocsRatchetList
{
    public const string PATH = 'ratchet/docs.json';
    public const string SHAPE = '{"paragraphs": {"<page>.md": {"<16 hex key>": <words>}}, "pages": {"<page>.md": <words>}}';

    private const string KEY = '/^[0-9a-f]{16}$/';
    private const array SECTIONS = ['pages', 'paragraphs'];
    private const int DEPTH = 4;

    // Because PHP turns a key of only digits into an integer, a listed paragraph key may come back as one.
    /**
     * @param array<string, array<int|string, int>> $paragraphs
     * @param array<string, int>                    $pages
     */
    private function __construct(
        public array $paragraphs,
        public array $pages,
    ) {
    }

    // Because a malformed list must fail the gate with a finding, every shape it does not expect gives null instead of an error.
    public static function fromJson(string $json): ?self
    {
        try {
            $decoded = json_decode($json, true, self::DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || self::SECTIONS !== self::sortedKeys($decoded)) {
            return null;
        }

        $paragraphs = self::paragraphsOf($decoded['paragraphs']);
        $pages = self::countsOf($decoded['pages']);

        return null === $paragraphs || null === $pages ? null : new self($paragraphs, $pages);
    }

    public function isEmpty(): bool
    {
        return [] === $this->paragraphs && [] === $this->pages;
    }

    public function paragraphCeiling(string $page, string $key): ?int
    {
        return $this->paragraphs[$page][$key] ?? null;
    }

    public function pageCeiling(string $page): ?int
    {
        return $this->pages[$page] ?? null;
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return list<int|string>
     */
    private static function sortedKeys(array $decoded): array
    {
        $keys = array_keys($decoded);
        sort($keys);

        return $keys;
    }

    /**
     * @return ?array<string, array<int|string, int>>
     */
    private static function paragraphsOf(mixed $section): ?array
    {
        if (!is_array($section)) {
            return null;
        }

        $paragraphs = [];

        foreach ($section as $page => $keys) {
            $counts = self::isMarkdownPath($page) && is_array($keys) && [] !== $keys && !array_is_list($keys) ? self::keyCountsOf($keys) : null;

            if (null === $counts) {
                return null;
            }

            $paragraphs[$page] = $counts;
        }

        return $paragraphs;
    }

    /**
     * @return ?array<string, int>
     */
    private static function countsOf(mixed $section): ?array
    {
        if (!is_array($section)) {
            return null;
        }

        $counts = [];

        foreach ($section as $page => $words) {
            if (!self::isMarkdownPath($page) || !self::isCount($words)) {
                return null;
            }

            $counts[$page] = $words;
        }

        return $counts;
    }

    /**
     * @phpstan-assert-if-true string $page
     */
    private static function isMarkdownPath(int|string $page): bool
    {
        // Checked before the name becomes a key of this list, since a numeric name such as "1" arrives as an integer.
        return is_string($page) && str_ends_with($page, '.md');
    }

    /**
     * @param array<mixed> $keys
     *
     * @return ?array<int|string, int>
     */
    private static function keyCountsOf(array $keys): ?array
    {
        $counts = [];

        foreach ($keys as $key => $words) {
            if (1 !== preg_match(self::KEY, (string) $key) || !self::isCount($words)) {
                return null;
            }

            $counts[$key] = $words;
        }

        return $counts;
    }

    /**
     * @phpstan-assert-if-true int $words
     */
    private static function isCount(mixed $words): bool
    {
        return is_int($words) && $words > 0;
    }
}
