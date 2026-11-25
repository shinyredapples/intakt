<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoundStatusLabel::class)]
final class RoundStatusLabelTest extends TestCase
{
    public function testEveryStoredStatusHasItsStatusLabel(): void
    {
        self::assertSame([RoundStatusLabel::InReview, RoundStatusLabel::WaitingOnYou], array_map(RoundStatusLabel::of(...), ReviewStatus::cases()));
    }
}
