<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Core\Review\Infrastructure\StoredLatestRoundStatus;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
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

    public function testTheStatusReadOfAChangeWithoutRoundsReadsNoAddressOfTheProjectsOtherPullRequests(): void
    {
        $this->roundOf('https://github.com/acme/widgets/pull/5');
        $this->roundOf('feat/other');
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        self::assertNull($this->latestStatus()->of('alpha', 'https://github.com/acme/widgets/pull/9'));
        self::assertNull($this->latestStatus()->of('alpha', 'feat/none'));

        $reads = array_filter(
            array_map(static fn (array $query): string => (string) $query['sql'], array_merge(...array_values($queries->getData()))),
            static fn (string $sql): bool => str_contains($sql, 'FROM review_round'),
        );
        self::assertNotSame([], $reads);
        foreach ($reads as $sql) {
            self::assertMatchesRegularExpression('/change_ref\s+(=|LIKE)\s/i', $sql);
        }
    }

    public function testARoundOfPullRequest123GivesNoStatusToPullRequest12(): void
    {
        $this->roundOf('https://github.com/acme/widgets/pull/123');

        self::assertNull($this->latestStatus()->of('alpha', 'https://github.com/acme/widgets/pull/12'));
        self::assertSame(ReviewStatus::InReview, $this->latestStatus()->of('alpha', 'https://github.com/acme/widgets/pull/123'));
    }

    public function testAPullRequestNamedWithAnotherSpellingFindsItsRound(): void
    {
        $this->roundOf('https://github.com/acme/widgets/pull/7');

        self::assertSame(ReviewStatus::InReview, $this->latestStatus()->of('alpha', 'https://GitHub.com/Acme/Widgets/pull/7/'));
    }

    public function testALocalBranchMatchesOnlyItsExactStoredSpelling(): void
    {
        $this->roundOf('feat/thing');

        self::assertNull($this->latestStatus()->of('alpha', 'FEAT/thing'));
        self::assertSame(ReviewStatus::InReview, $this->latestStatus()->of('alpha', 'feat/thing'));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function latestStatus(): StoredLatestRoundStatus
    {
        return new StoredLatestRoundStatus($this->fixture->transactions());
    }

    private function roundOf(string $change): void
    {
        $this->fixture->startFor($change, $this->fixture->bus, 2);
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
