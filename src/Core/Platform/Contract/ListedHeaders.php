<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

// The redacted header lists with where the text after each kept list resumes, because the value pass must skip only that list's own part.
final readonly class ListedHeaders
{
    /** @var array<int, int> */
    private array $keptTails;

    public function __construct(
        public RedactedSpans $spans,
        KeptTail ...$keptTails,
    ) {
        $this->keptTails = array_column(array_map(static fn (KeptTail $tail): array => [$tail->listStart, $tail->end], $keptTails), 1, 0);
    }

    public function tailKeptFrom(int $listStart): ?int
    {
        return $this->keptTails[$listStart] ?? null;
    }
}
