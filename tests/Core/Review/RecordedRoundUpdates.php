<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review;

use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\RoundUpdates;
use Override;
use Symfony\Component\Uid\Uuid;

final class RecordedRoundUpdates implements RoundUpdates
{
    /**
     * @var list<string>
     */
    public array $seen = [];

    #[Override]
    public function toolCalled(Uuid $round, string $reviewer, string $tool): void
    {
        $this->seen[] = sprintf('tool %s %s', $reviewer, $tool);
    }

    #[Override]
    public function textStreamed(Uuid $round, string $reviewer, string $text): void
    {
        $this->seen[] = sprintf('text %s %s', $reviewer, $text);
    }

    #[Override]
    public function roundChanged(Uuid $round, RoundProgress $progress): void
    {
        $this->seen[] = sprintf('round %s %d/%d%s', $round->toRfc4122(), $progress->reviewersFinished, $progress->reviewers, $progress->judged ? ' judged' : '');
    }
}
