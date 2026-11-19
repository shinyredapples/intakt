<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class CheckedMain
{
    /**
     * @param list<CommitClassification> $commits oldest first; a rewritten history holds the new head alone
     */
    public function __construct(
        public string $head,
        public array $commits,
    ) {
    }

    /**
     * @return list<CommitClassification>
     */
    public function violations(): array
    {
        return array_values(array_filter($this->commits, static fn (CommitClassification $commit): bool => $commit->verdict->isViolation()));
    }

    public function isClean(): bool
    {
        return [] === $this->violations();
    }
}
