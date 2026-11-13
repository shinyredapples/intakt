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
}
