<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Git\Contract\Paths;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\Uuid;

#[Exclude]
final readonly class JudgeRequest
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public string $task,
        public array $findings,
        // Carried to the call's usage row, because a round's token count reads its calls by the round id.
        public Uuid $round,
        // Because only a file the change already touches makes a P3 edit local, and the judge cannot see the diff.
        public Paths $changedFiles,
    ) {
    }
}
