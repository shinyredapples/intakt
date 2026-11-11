<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

final readonly class PublishedHead
{
    /**
     * @param list<ReviewerDecision> $loopDecisions
     * @param list<ReviewerDecision> $intaktDecisions
     * @param list<LoopFinding>      $loopOnly
     * @param list<LoopFinding>      $intaktOnly
     */
    public function __construct(
        public int $pullRequest,
        public string $head,
        public array $loopDecisions,
        public array $intaktDecisions,
        public Agreement $agreement,
        public int $matched,
        public array $loopOnly,
        public array $intaktOnly,
    ) {
    }
}
