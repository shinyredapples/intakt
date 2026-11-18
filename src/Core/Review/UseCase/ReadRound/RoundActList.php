<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ReplayMark;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class RoundActList
{
    /**
     * @var list<Finding>
     */
    public array $act;

    /**
     * @param list<Finding> $act
     */
    public function __construct(
        public string $roundId,
        public bool $judged,
        array $act,
        public ReplayMark $mark,
    ) {
        $this->act = array_map(static fn (Finding $finding): Finding => $finding->withJudgeNoteFenced(), $act);
    }
}
