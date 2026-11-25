<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(StoredLatestRoundStatus::class)]
#[UsesClass(ReviewRounds::class)]
#[UsesClass(ReviewStatus::class)]
final class StoredLatestRoundStatusTest extends KernelTestCase
{
    private RoundFixture $fixture;

    public function testAChangeNoRoundReviewedHasNoStatus(): void
    {
        self::assertNull($this->latestStatus()->of('alpha', RoundFixture::CHANGE));
    }

    public function testAQueuedRoundIsInReview(): void
    {
        $this->fixture->startFor(RoundFixture::CHANGE, $this->fixture->bus, 2);

        self::assertSame(ReviewStatus::InReview, $this->latestStatus()->of('alpha', RoundFixture::CHANGE));
    }

    public function testAJudgedRoundWhosePublicationHasNotRunStaysInReview(): void
    {
        $this->judgedRound();

        self::assertSame(ReviewStatus::InReview, $this->latestStatus()->of('alpha', RoundFixture::CHANGE));
    }

    public function testAJudgedRoundWhoseVerdictWasPublishedIsWaitingOnYou(): void
    {
        $this->publishedRound();

        self::assertSame(ReviewStatus::WaitingOnYou, $this->latestStatus()->of('alpha', RoundFixture::CHANGE));
    }

    public function testAPublicationAnotherWorkerRecordsAfterThisReaderReadTheRoundIsSeen(): void
    {
        $roundId = $this->judgedRound();
        $status = $this->latestStatus();
        self::assertSame(ReviewStatus::InReview, $status->of('alpha', RoundFixture::CHANGE));

        $writer = $this->fixture->anotherWorkersRounds();
        $writer->recordPublication($writer->stored($roundId));

        self::assertSame(ReviewStatus::WaitingOnYou, $status->of('alpha', RoundFixture::CHANGE));
    }

    public function testANewRoundAfterAPublishedVerdictIsInReviewAgain(): void
    {
        $this->publishedRound();

        $this->fixture->startFor(RoundFixture::CHANGE, $this->fixture->bus, 2);

        self::assertSame(ReviewStatus::InReview, $this->latestStatus()->of('alpha', RoundFixture::CHANGE));
    }

    public function testAPublishedVerdictOfAnotherProjectLeavesTheChangeWithoutStatus(): void
    {
        $this->publishedRound();

        self::assertNull($this->latestStatus()->of('beta', RoundFixture::CHANGE));
    }

    public function testAPullRequestWithoutARoundLoadsNoRoundOfTheProjectsOtherPullRequests(): void
    {
        foreach (range(1, 5) as $number) {
            $this->fixture->startFor('https://github.com/acme/widgets/pull/'.$number, $this->fixture->bus, 2);
        }
        $this->fixture->entities->clear();

        $status = $this->latestStatus()->of('alpha', 'https://github.com/acme/widgets/pull/99');

        self::assertNull($status);
        self::assertCount(0, $this->fixture->entities->getUnitOfWork()->getIdentityMap()[ReviewRound::class] ?? []);
    }

    public function testAPullRequestWithARoundLoadsOnlyItsOwnRounds(): void
    {
        foreach (range(1, 3) as $number) {
            $this->fixture->startFor('https://github.com/acme/widgets/pull/'.$number, $this->fixture->bus, 2);
        }
        $this->fixture->entities->clear();

        $status = $this->latestStatus()->of('alpha', 'https://github.com/acme/widgets/pull/2');

        self::assertSame(ReviewStatus::InReview, $status);
        self::assertCount(1, $this->fixture->entities->getUnitOfWork()->getIdentityMap()[ReviewRound::class] ?? []);
    }

    public function testARoundLeftUnderAnOldSpellingStillGivesItsPullRequestAStatus(): void
    {
        $this->fixture->startFor('https://github.com/acme/widgets/pull/7', $this->fixture->bus, 2);
        foreach (['change_findings', 'review_round'] as $table) {
            $this->fixture->entities->getConnection()->executeStatement(sprintf('UPDATE %s SET change_ref = ? WHERE change_ref = ?', $table), ['https://github.com/Acme/Widgets/pull/7', 'https://github.com/acme/widgets/pull/7']);
        }
        $this->fixture->entities->clear();

        self::assertSame(ReviewStatus::InReview, $this->latestStatus()->of('alpha', 'https://github.com/acme/widgets/pull/7'));
        self::assertNull($this->latestStatus()->of('alpha', 'https://github.com/acme/widgets/pull/8'));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function latestStatus(): StoredLatestRoundStatus
    {
        return new StoredLatestRoundStatus($this->fixture->transactions());
    }

    private function publishedRound(): void
    {
        $roundId = $this->judgedRound();
        $rounds = $this->fixture->transactions();

        $rounds->recordPublication($rounds->stored($roundId));
    }

    private function judgedRound(): string
    {
        $vendor = new VerdictPerRole([]);
        $sent = count($this->fixture->bus->messages);
        $judged = count($this->fixture->judgings());
        $roundId = $this->fixture->startFor(RoundFixture::CHANGE, $this->fixture->bus, 2);
        foreach (array_slice($this->fixture->bus->messages, $sent) as $message) {
            $this->fixture->deliver($message, $vendor);
        }
        foreach (array_slice($this->fixture->judgings(), $judged) as $judging) {
            $this->fixture->judge($vendor)($judging);
        }

        return $roundId;
    }
}
