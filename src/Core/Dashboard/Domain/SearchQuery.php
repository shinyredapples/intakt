<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class SearchQuery
{
    public string $text;

    /** @var list<string> */
    private array $words;

    public function __construct(string $text)
    {
        // Scrubbed first, as the address may carry bytes that are not UTF-8; trim() would keep a no-break or em space.
        $this->text = preg_replace('/^[\s\x00\p{Z}]+|[\s\x00\p{Z}]+$/u', '', mb_scrub($text)) ?? '';
        $words = preg_split('/[\s\p{Z}]+/u', mb_strtolower($this->text), flags: PREG_SPLIT_NO_EMPTY);
        $this->words = false === $words ? [] : $words;
    }

    public function isEmpty(): bool
    {
        return [] === $this->words;
    }

    public function matches(SearchResult $result): bool
    {
        $haystack = mb_strtolower(implode(' ', [$result->label, $result->detail, $result->group->value]));

        return array_all($this->words, static fn (string $word): bool => str_contains($haystack, $word));
    }
}
