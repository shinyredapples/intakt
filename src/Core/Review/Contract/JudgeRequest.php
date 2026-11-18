<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Uid\Uuid;

#[Exclude]
final readonly class JudgeRequest
{
    /**
     * @param list<Finding> $findings
     * @param list<string>  $changedFiles
     */
    public function __construct(
        public string $task,
        public array $findings,
        // Carried to the call's usage row, because a round's token count reads its calls by the round id.
        public Uuid $round,
        // Lets the judge tell a file the change already touches from one outside it.
        public array $changedFiles = [],
    ) {
    }
}
