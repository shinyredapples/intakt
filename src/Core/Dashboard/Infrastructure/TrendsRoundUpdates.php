<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Uid\Uuid;

// A round change and a reviewer start only, because those add a round to the figures, move a round into Done or change who is running; a tool call or a text changes none of them.
#[AsDecorator(RoundUpdates::class)]
final readonly class TrendsRoundUpdates implements RoundUpdates
{
    public function __construct(
        #[AutowireDecorated]
        private RoundUpdates $round,
        private LiveTrends $trends,
    ) {
    }

    #[Override]
    public function toolCalled(Uuid $round, string $reviewer, string $call): void
    {
        $this->round->toolCalled($round, $reviewer, $call);
    }

    #[Override]
    public function textStreamed(Uuid $round, string $reviewer, string $text): void
    {
        $this->round->textStreamed($round, $reviewer, $text);
    }

    #[Override]
    public function answerRead(Uuid $round, string $reviewer, ReadAnswer $answer): void
    {
        $this->round->answerRead($round, $reviewer, $answer);
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
        $this->trends->publishLatest($round);
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
        $this->trends->publishLatest($round);
    }
}
