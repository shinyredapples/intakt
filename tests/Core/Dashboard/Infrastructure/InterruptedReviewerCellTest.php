<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Environment;

#[CoversClass(ReviewOutcome::class)]
final class InterruptedReviewerCellTest extends KernelTestCase
{
    public function testAnInterruptedReviewersCardReadsWaitingForTheNetwork(): void
    {
        $cell = self::getContainer()->get(Environment::class)->render('dashboard/_reviewer_state_cell.html.twig', [
            'round' => 'r1',
            'outcome' => ['id' => 'x1', 'progress' => ReviewerProgress::Interrupted, 'outcome' => null, 'note' => null, 'substitute' => null],
        ]);

        self::assertStringContainsString('Waiting for the network', $cell);
    }
}
