<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use Intakt\Core\Dashboard\Domain\BoardColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoardColumn::class)]
final class BoardColumnTest extends TestCase
{
    public function testOnlyBacklogAndDoneStartCollapsed(): void
    {
        $collapsed = array_filter(BoardColumn::cases(), static fn (BoardColumn $column): bool => $column->startsCollapsed());

        self::assertSame([BoardColumn::Backlog, BoardColumn::Done], array_values($collapsed));
    }

    public function testAnEmptyInReviewSaysThatReviewsAppearWhileAPanelReviews(): void
    {
        self::assertSame('Reviews appear here while a panel reviews a change', BoardColumn::InReview->emptyLine());
    }

    public function testEveryEmptyColumnSaysWhatAppearsInItInALineOfItsOwn(): void
    {
        $lines = array_map(static fn (BoardColumn $column): string => $column->emptyLine(), BoardColumn::cases());

        self::assertSame($lines, array_values(array_unique($lines)));
        self::assertSame([], array_filter($lines, static fn (string $line): bool => !str_contains($line, ' here ')));
    }
}
