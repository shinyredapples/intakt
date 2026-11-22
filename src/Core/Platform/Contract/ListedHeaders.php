<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

// The redacted header lists with where the text after each kept list resumes, because the value pass must skip only that list's own part.
final readonly class ListedHeaders
{
    /**
     * @param array<int, int> $keptTails the end of each list whose following text is kept, by the list's start
     */
    public function __construct(
        public RedactedSpans $spans,
        private array $keptTails,
    ) {
    }

    public function tailKeptFrom(int $listStart): ?int
    {
        return $this->keptTails[$listStart] ?? null;
    }
}
