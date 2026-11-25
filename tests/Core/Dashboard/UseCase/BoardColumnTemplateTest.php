<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Environment;

#[CoversClass(Board::class)]
final class BoardColumnTemplateTest extends KernelTestCase
{
    // The layout puts this tab's own fold back on a pushed column, so a Done column rendered open still needs the strip that opens it again.
    public function testADoneColumnRenderedOpenOnABoardWithoutOtherCardsStillHasItsStrip(): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('dashboard/_board_column.html.twig', [
            'board' => new Board(new DateTimeImmutable('2026-11-01T10:30:00+00:00'), []),
            'column' => BoardColumn::Done,
            'limit' => Board::PAGE_LIMIT,
        ]);

        self::assertStringContainsString('class="col-fold" aria-expanded="true"', $html);
    }
}
