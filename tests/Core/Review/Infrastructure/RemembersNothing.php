<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Memory\Contract\MemoryQuery;
use Intakt\Core\Memory\Contract\ProjectMemory;
use Intakt\Core\Memory\Contract\RelevantMemory;
use Intakt\Core\Memory\Contract\SettledMemory;
use Intakt\Core\Memory\Contract\SettledQuery;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\DiffPaths;
use Intakt\Core\Review\Infrastructure\ReviewerMemory;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Override;

final readonly class RemembersNothing implements ProjectMemory
{
    public static function prompt(): ReviewerPrompt
    {
        return new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser(), new ReviewerMemory(new self(), new Fence(), new DiffPaths()));
    }

    #[Override]
    public function relevantTo(MemoryQuery $query): RelevantMemory
    {
        return RelevantMemory::within([], $query->maxBytes);
    }

    #[Override]
    public function settledOn(SettledQuery $query): SettledMemory
    {
        return SettledMemory::none();
    }
}
