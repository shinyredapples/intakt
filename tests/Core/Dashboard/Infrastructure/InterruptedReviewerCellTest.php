<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Twig\Environment;

#[CoversClass(ReviewOutcome::class)]
#[CoversClass(ReviewerOutcome::class)]
final class InterruptedReviewerCellTest extends KernelTestCase
{
    public function testAnInterruptedReviewersCardReadsWaitingForTheNetwork(): void
    {
        self::assertStringContainsString('Waiting for the network', $this->cell(1));
    }

    public function testTheCardShowsHowOftenTheReviewerWasInterrupted(): void
    {
        self::assertStringContainsString('3 interruptions', $this->cell(3));
    }

    public function testTheCardNamesASingleInterruptionInTheSingular(): void
    {
        self::assertStringContainsString('>1 interruption<', $this->cell(1));
    }

    private function cell(int $interruptions): string
    {
        $outcome = new ReviewerOutcome('x1', 'correctness', 'standard', new DateTimeImmutable('2026-11-20T10:00:00+00:00'))->interrupted($interruptions);

        return self::getContainer()->get(Environment::class)->render('dashboard/_reviewer_state_cell.html.twig', ['round' => 'r1', 'outcome' => $outcome]);
    }
}
