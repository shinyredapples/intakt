<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\Headline;
use Intakt\Core\Dashboard\Domain\Lane;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Domain\WhoIsWorking;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Headline::class)]
#[UsesClass(WhoIsWorking::class)]
#[UsesClass(Lane::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class HeadlineTest extends TestCase
{
    public function testAFreshInstallSaysNothingIsRunningYetAndAsksForTheFirstReview(): void
    {
        $headline = new Headline(new WhoIsWorking($this->now(),[]), 0);

        self::assertSame('Nothing is running yet. Start the first review.', $headline->opening());
    }

    public function testOnlyAHeadlineWithoutCardsIsTheFirstDay(): void
    {
        self::assertSame([true, false], [new Headline(new WhoIsWorking($this->now(),[]), 0)->isFirstDay(), new Headline(new WhoIsWorking($this->now(),[]), 1)->isFirstDay()]);
    }

    public function testOneRunningReviewNamesTheCodeReviewersAndItsChange(): void
    {
        $headline = $this->headline([$this->running('sample', '3')]);

        self::assertSame('The Code reviewers are reviewing change 3 of sample.', $headline->opening());
    }

    public function testARoundOpenedBeforeItsFirstReviewerStartsIsNamedAndLinked(): void
    {
        $opened = new Review([new ReviewedRound(new RoundStart('r-opened', 'sample', 2), new RoundPlace('3', 1), new DateTimeImmutable('2026-11-01T10:00:00+00:00'), null)]);

        $headline = new Headline(new WhoIsWorking($this->now(),[$opened]), 1);

        self::assertSame(['The Code reviewers are reviewing change 3 of sample.', 'r-opened'], [$headline->opening(), $headline->runningRound()]);
    }

    public function testTheReviewersOfOneChangeAreNamedOnce(): void
    {
        $headline = $this->headline([$this->running('sample', '3', 'correctness', 'security')]);

        self::assertSame('The Code reviewers are reviewing change 3 of sample.', $headline->opening());
    }

    public function testTwoRunningReviewsAreBothNamed(): void
    {
        $headline = $this->headline([$this->running('sample', '3'), $this->running('shop', '5')]);

        self::assertSame('The Code reviewers are reviewing change 3 of sample and change 5 of shop.', $headline->opening());
    }

    public function testThreeRunningReviewsNameTwoAndCountOneMore(): void
    {
        $headline = $this->headline([$this->running('sample', '3'), $this->running('shop', '5'), $this->running('docs', '8')]);

        self::assertSame('The Code reviewers are reviewing change 3 of sample, change 5 of shop and 1 more change.', $headline->opening());
    }

    public function testManyRunningReviewsNameTheFirstTwoAndCountTheRest(): void
    {
        $reviews = array_map(fn (int $change): Review => $this->running('sample', (string) $change), range(1, 6));

        $headline = $this->headline($reviews);

        self::assertSame('The Code reviewers are reviewing change 1 of sample, change 2 of sample and 4 more changes.', $headline->opening());
    }

    public function testTheShortOpeningCountsTheRunningChangesInsteadOfNamingThem(): void
    {
        $headline = $this->headline([$this->running('a-project-with-a-very-long-name-for-tests', '3'), $this->running('shop', '5'), $this->running('docs', '2')]);

        self::assertSame('The Code reviewers are reviewing 3 changes.', $headline->shortOpening());
    }

    public function testTheShortOpeningOfOneRunningChangeSaysChange(): void
    {
        $headline = $this->headline([$this->running('a-project-with-a-very-long-name-for-tests', '3')]);

        self::assertSame('The Code reviewers are reviewing 1 change.', $headline->shortOpening());
    }

    public function testWithNothingRunningTheShortOpeningIsTheOpening(): void
    {
        self::assertSame(['Nothing is running.', 'Nothing is running yet. Start the first review.'], [new Headline(new WhoIsWorking($this->now(),[]), 1)->shortOpening(), new Headline(new WhoIsWorking($this->now(),[]), 0)->shortOpening()]);
    }

    public function testAFreshInstallOpensWithTheWholeLine(): void
    {
        $headline = new Headline(new WhoIsWorking($this->now(),[]), 0);

        self::assertSame('Nothing is running yet. Start the first review.', $headline->opening());
    }

    public function testABoardWithReviewsButNoneRunningSaysNothingIsRunning(): void
    {
        $headline = new Headline(new WhoIsWorking($this->now(),[]), 1);

        self::assertSame('Nothing is running.', $headline->opening());
    }

    public function testABuildInFlightWithNoReviewerRunningSaysABuildIsRunning(): void
    {
        $headline = new Headline(new WhoIsWorking($this->now(),[]), 1, 1);

        self::assertSame(['A build is running.', 'A build is running.'], [$headline->opening(), $headline->shortOpening()]);
    }

    public function testSeveralBuildsInFlightAreCounted(): void
    {
        self::assertSame('3 builds are running.', new Headline(new WhoIsWorking($this->now(),[]), 3, 3)->opening());
    }

    public function testARunningReviewerIsNamedBeforeABuildInFlight(): void
    {
        $headline = new Headline(new WhoIsWorking($this->now(),[$this->running('sample', '3')]), 2, 1);

        self::assertSame('The Code reviewers are reviewing change 3 of sample.', $headline->opening());
    }

    public function testALongProjectNameIsCutToThirtyTwoCharactersWithAnEllipsis(): void
    {
        $headline = $this->headline([$this->running('a-project-with-a-very-long-name-for-tests', '3')]);

        self::assertSame('The Code reviewers are reviewing change 3 of a-project-with-a-very-long-name….', $headline->opening());
    }

    public function testAProjectNameOfThirtyTwoCharactersIsKeptWhole(): void
    {
        $name = str_repeat('p', 32);

        $headline = $this->headline([$this->running($name, '3')]);

        self::assertSame('The Code reviewers are reviewing change 3 of '.$name.'.', $headline->opening());
    }

    public function testAProjectNameOfThirtyThreeCharactersIsCut(): void
    {
        $headline = $this->headline([$this->running(str_repeat('p', 33), '3')]);

        self::assertSame('The Code reviewers are reviewing change 3 of '.str_repeat('p', 31).'….', $headline->opening());
    }

    public function testAProjectNameOfWideCharactersIsCutByDisplayWidth(): void
    {
        $headline = $this->headline([$this->running(str_repeat('項', 20), '3')]);

        self::assertSame('The Code reviewers are reviewing change 3 of '.str_repeat('項', 15).'….', $headline->opening());
    }

    public function testALongChangeNameIsCutLikeAProjectName(): void
    {
        $headline = $this->headline([$this->running('sample', 'feat/a-branch-name-that-goes-on-and-on')]);

        self::assertSame('The Code reviewers are reviewing change feat/a-branch-name-that-goes-on… of sample.', $headline->opening());
    }

    public function testChangeNamesThatReadAsTheSameNumberAreDifferentChanges(): void
    {
        $headline = $this->headline([$this->running('sample', '1'), $this->running('sample', '01'), $this->running('sample', '1e0')]);

        self::assertSame('The Code reviewers are reviewing change 1 of sample, change 01 of sample and 1 more change.', $headline->opening());
    }

    public function testAProjectAndChangeThatJoinToTheSameTextAreDifferentPlaces(): void
    {
        $headline = $this->headline([$this->running('ab', 'c'), $this->running('a', 'bc')]);

        self::assertSame('The Code reviewers are reviewing change c of ab and change bc of a.', $headline->opening());
    }

    public function testRunningRoundsFromBeforeTheLedgerNamedTheirChangeAreNamedByTheirRound(): void
    {
        $headline = $this->headline([$this->unnamed('legacy-round-a'), $this->unnamed('legacy-round-b')]);

        self::assertSame('The Code reviewers are reviewing round legacy-round-a of sample and round legacy-round-b of sample.', $headline->opening());
    }

    public function testTheReviewersOfOneUnnamedRoundAreNamedOnce(): void
    {
        $headline = $this->headline([$this->unnamed('legacy-round-a', 'correctness', 'security')]);

        self::assertSame('The Code reviewers are reviewing round legacy-round-a of sample.', $headline->opening());
    }

    public function testTheRunningRoundIsTheRoundOfTheChangeTheOpeningNamesFirst(): void
    {
        $headline = $this->headline([$this->running('sample', '3'), $this->running('sample', '5')]);

        self::assertSame('r-sample-3', $headline->runningRound());
    }

    public function testABoardWithNothingRunningHasNoRunningRound(): void
    {
        self::assertNull(new Headline(new WhoIsWorking($this->now(),[]), 1)->runningRound());
    }

    public function testARoundFromBeforeTheLedgerNamedItsChangeIsStillTheRunningRound(): void
    {
        self::assertSame('legacy-round-a', $this->headline([$this->unnamed('legacy-round-a')])->runningRound());
    }

    private function running(string $project, string $change, string ...$roles): Review
    {
        return $this->review(new RoundStart('r-'.$project.'-'.$change, $project, 2), $change, ...$roles);
    }

    /**
     * @param list<Review> $running
     */
    private function headline(array $running): Headline
    {
        return new Headline(new WhoIsWorking($this->now(),$running), count($running));
    }

    private function unnamed(string $round, string ...$roles): Review
    {
        return $this->review(new RoundStart($round, 'sample', 2), '', ...$roles);
    }

    private function review(RoundStart $start, string $change, string ...$roles): Review
    {
        $since = new DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $outcomes = array_map(static fn (string $role): ReviewerOutcome => new ReviewerOutcome($start->round.'-'.$role, $role, 'standard', $since), [] === $roles ? ['correctness'] : array_values($roles));

        return new Review([new ReviewedRound($start, new RoundPlace($change, 1), $since, null)->withOutcomes($outcomes)]);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-01T10:30:00+00:00');
    }
}
