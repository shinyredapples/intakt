<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Domain\ReviewerState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FinishedState::class)]
#[UsesClass(ReviewerState::class)]
final class FinishedStateTest extends TestCase
{
    public function testEveryFinishedReviewerStateHasAFinishedStateOfTheSameValue(): void
    {
        $finished = array_values(array_filter(ReviewerState::cases(), static fn (ReviewerState $state): bool => $state->isFinished()));

        self::assertSame(array_column($finished, 'value'), array_map(static fn (ReviewerState $state): string => FinishedState::from($state->value)->value, $finished));
    }

    public function testOnlyStoppedIsNotAReviewerState(): void
    {
        $extra = array_filter(FinishedState::cases(), static fn (FinishedState $state): bool => !ReviewerState::tryFrom($state->value) instanceof ReviewerState);

        self::assertSame([FinishedState::Stopped], array_values($extra));
    }
}
