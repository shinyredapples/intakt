<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class JudgeRequest
{
    /**
     * @param list<Finding> $findings
     */
    public function __construct(
        public string $task,
        public array $findings,
    ) {
    }
}
