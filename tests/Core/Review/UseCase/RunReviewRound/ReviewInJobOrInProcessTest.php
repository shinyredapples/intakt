<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Job\Contract\ContainerEngine;
use Intakt\Core\Review\Contract\JobReview;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

// Maintainer decision of 2026-11-13 on #2017: an install without a container engine keeps reviewing in its own process, and only one with an engine reviews in a job.
#[Group('persistence')]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(ContainerEngine::class)]
#[CoversClass(JobReview::class)]
#[UsesClass(CommitId::class)]
final class ReviewInJobOrInProcessTest extends KernelTestCase
{
    public function testAnInstallWithoutAContainerEngineReviewsInItsOwnProcess(): void
    {
        $vendor = $this->reviewedOn(new RoundFixture(self::getContainer()));

        self::assertSame([null], $vendor->jobs);
    }

    public function testAnInstallWithAContainerEngineReviewsInAJobAtTheRoundsHead(): void
    {
        $fixture = new RoundFixture(self::getContainer(), engine: new ContainerEngine('docker'));

        $vendor = $this->reviewedOn($fixture);

        $reviewer = $fixture->reviewer($fixture->bus->messages[0]->reviewerId);
        self::assertEquals([new JobReview($reviewer->round()->identity()->commits->head, $reviewer->roleName())], $vendor->jobs);
    }

    public function testAReviewInAJobEndsWithTheVerdictItsReviewerGave(): void
    {
        $fixture = new RoundFixture(self::getContainer(), engine: new ContainerEngine('docker'));

        $this->reviewedOn($fixture);

        self::assertSame(ReviewerState::Verdict, $fixture->reviewer($fixture->bus->messages[0]->reviewerId)->state());
    }

    private function reviewedOn(RoundFixture $fixture): ScriptedVendor
    {
        $fixture->start(1);
        $vendor = new ScriptedVendor();
        $fixture->deliver($fixture->bus->messages[0], $vendor);

        return $vendor;
    }
}
