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
        // Scrubbed first, because the address may carry bytes that are not UTF-8 and the word split refuses them.
        $this->text = trim(mb_scrub($text));
        $this->words = preg_split('/\s+/u', mb_strtolower($this->text), flags: PREG_SPLIT_NO_EMPTY) ?: [];
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
