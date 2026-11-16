<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\Verdict;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Uid\Uuid;

// Only a start and a round change move the headline, because those are the events that add or remove a lane or a card; a tool call or a text changes neither.
#[AsDecorator(RoundUpdates::class)]
final readonly class BoardHeadlineRoundUpdates implements RoundUpdates
{
    public function __construct(
        #[AutowireDecorated]
        private RoundUpdates $round,
        private LiveBoardHeadline $headline,
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
    public function answerGiven(Uuid $round, string $reviewer, ?Verdict $verdict): void
    {
        $this->round->answerGiven($round, $reviewer, $verdict);
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
        $this->headline->publishLatest($round);
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
        $this->headline->publishLatest($round);
    }
}
