<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class HighlightsRow
{
    // Matches CommonMark, because ``a`b`` is one span and an escaped backtick opens none.
    private const string BACKTICKED = '/\\\\.(*SKIP)(*FAIL)|(`+)(?!`)(?:(.+?)(?<!`)\1(?!`)|(*SKIP)(*FAIL))/';

    // One space on each side is padding, so a span can start or end with a backtick (CommonMark).
    private const string PADDED_SPAN = '/^ (.*[^ ].*) $/';

    private const string PATH_LIKE = '#^(?:[^*<>{}]+/[^*<>{}]*|[^*<>{}/]*\.\w+)$#';

    /**
     * @param list<string> $headings
     */
    public function __construct(
        public int $line,
        public array $headings,
        public MarkdownBlock $block,
        public ?string $status,
        public ?string $where,
    ) {
    }

    public function isRendered(): bool
    {
        return MarkdownBlock::Text === $this->block;
    }

    public function isUnder(string $heading): bool
    {
        return in_array($heading, $this->headings, true);
    }

    // Null when the pattern gives up on a long cell (the backtrack limit), which must not read as a clean cell.
    public function whereOutsideSpans(): ?string
    {
        return null === $this->where ? '' : preg_replace(self::BACKTICKED, '', $this->where);
    }

    // As written, so a finding quotes the cell; null for the same reason as above.

    /**
     * @return list<string>|null
     */
    public function paths(): ?array
    {
        if (null === $this->where) {
            return [];
        }

        if (false === preg_match_all(self::BACKTICKED, $this->where, $matches)) {
            return null;
        }

        $paths = [];

        foreach ($matches[2] as $span) {
            // Falling back to the raw span keeps a failed match checked, since padding makes no tracked path.
            $path = preg_replace(self::PADDED_SPAN, '$1', $span) ?? $span;

            if (1 === preg_match(self::PATH_LIKE, $path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
