<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Uid\Uuid;

// Beside the round's own updates, because the board headline changes with the same progress the round page shows: a reviewer starting or finishing, and the judgement.
#[AsDecorator(RoundUpdates::class)]
final readonly class HeadlineRoundUpdates implements RoundUpdates
{
    public function __construct(
        #[AutowireDecorated]
        private RoundUpdates $round,
        private PublishBoardHeadline $headline,
    ) {
    }

    #[Override]
    public function toolCalled(Uuid $round, string $reviewer, string $tool): void
    {
        $this->round->toolCalled($round, $reviewer, $tool);
    }

    #[Override]
    public function textStreamed(Uuid $round, string $reviewer, string $text): void
    {
        $this->round->textStreamed($round, $reviewer, $text);
    }

    #[Override]
    public function reviewerAskedAgain(Uuid $round, string $reviewer, string $try): void
    {
        $this->round->reviewerAskedAgain($round, $reviewer, $try);
    }

    #[Override]
    public function roundChanged(Uuid $round, RoundProgress $progress): void
    {
        $this->round->roundChanged($round, $progress);
        $this->headline->progressed($round->toRfc4122());
    }

    #[Override]
    public function summaryChanged(Uuid $round): void
    {
        $this->round->summaryChanged($round);
    }

    #[Override]
    public function reviewerStopped(Uuid $round, string $reviewer): void
    {
        $this->round->reviewerStopped($round, $reviewer);
    }

    #[Override]
    public function reviewerStarted(Uuid $round, string $reviewer): void
    {
        $this->round->reviewerStarted($round, $reviewer);
        $this->headline->progressed($round->toRfc4122());
    }
}
