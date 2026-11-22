<?php

declare(strict_types=1);

namespace Intakt\Core\Memory\Contract;

use Intakt\Core\Git\Contract\Paths;

final readonly class SettledQuery
{
    /**
     * @var list<CodeSnapshot>
     */
    public array $current;

    // The current snapshots are the code as it is on main now, because only the caller knows it; one per file and symbol it asks about.
    public function __construct(
        public string $project,
        CodeSnapshot ...$current,
    ) {
        $this->current = array_values($current);
    }

    public function files(): Paths
    {
        return new Paths(...array_values(array_unique(array_map(static fn (CodeSnapshot $snapshot): string => $snapshot->file, $this->current))));
    }

    public function currentCodeOf(SettledFinding $finding): ?CodeSnapshot
    {
        foreach ($this->current as $snapshot) {
            if ($finding->describes($snapshot)) {
                return $snapshot;
            }
        }

        return null;
    }
}
