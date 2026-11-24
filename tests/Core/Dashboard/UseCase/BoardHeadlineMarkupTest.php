<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

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
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

// The board cannot hold a waiting card until a module records one, so the shared partial is rendered with a headline built here.
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
final class BoardHeadlineMarkupTest extends KernelTestCase
{
    private const string PARTIAL = 'dashboard/_board_headline.html.twig';

    public function testTheBoardHeadlineLeavesWhatWaitsToTheNeedsYouCard(): void
    {
        $running = $this->review('round-3', '3');

        $line = $this->rendered(new Headline(new WhoIsWorking($this->now(), [$running]), 1));

        self::assertSame('The Code reviewers are reviewing change 3 of sample. The Code reviewers are reviewing 1 change.', $line->text());
        self::assertCount(0, $line->filter('.count'));
        self::assertSame(['/reviews/round-3'], $line->filter('a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
    }

    public function testTheLeadLinksToTheReviewOfTheRunningRound(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking($this->now(), [$this->review('round-3', '3'), $this->review('round-5', '5')]), 2));

        $link = $line->filter('a');

        self::assertSame(['/reviews/round-3'], $link->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertSame('The Code reviewers are reviewing change 3 of sample and change 5 of sample. The Code reviewers are reviewing 2 changes.', $link->text());
    }

    public function testTheLeadLinkCarriesTheClassThatSizesItsTouchTarget(): void
    {
        self::assertCount(1, $this->rendered(new Headline(new WhoIsWorking($this->now(), [$this->review('round-3', '3')]), 1))->filter('a.headline-run'));
    }

    public function testTheLeadHasNoLinkWhenNothingIsRunning(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking($this->now(), []), 1));

        self::assertCount(0, $line->filter('a'));
    }

    public function testARunningChangeIsNamedOnWideScreensAndCountedOnPhones(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking($this->now(), [$this->review('round-3', '3'), $this->review('round-5', '5')]), 2));

        self::assertSame('The Code reviewers are reviewing change 3 of sample and change 5 of sample.', $line->filter('.headline-long')->text());
        self::assertSame('The Code reviewers are reviewing 2 changes.', $line->filter('.headline-short')->text());
    }

    public function testWithNothingRunningTheHeadlineHasOneOpening(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking($this->now(), []), 1));

        self::assertSame('Nothing is running.', $line->text());
        self::assertCount(0, $line->filter('.headline-long, .headline-short'));
    }

    public function testAFreshInstallsHeadlineIsItsOpeningAlone(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking($this->now(), []), 0));

        self::assertSame('Nothing is running yet. Start the first review.', $line->text());
        self::assertCount(0, $line->filter('.count'));
    }

    public function testAFreshInstallsHeadlineLinksTheFirstRunPage(): void
    {
        $link = $this->rendered(new Headline(new WhoIsWorking($this->now(), []), 0))->filter('a');

        self::assertSame(['/setup'], $link->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertSame('Nothing is running yet. Start the first review.', $link->text());
    }

    public function testAFreshInstallsHeadlineLinkCarriesTheClassThatSizesItsTouchTarget(): void
    {
        self::assertCount(1, $this->rendered(new Headline(new WhoIsWorking($this->now(), []), 0))->filter('a.headline-setup'));
    }

    private function review(string $round, string $change): Review
    {
        $since = new DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $start = new RoundStart($round, 'sample', 2);

        return new Review([new ReviewedRound($start, new RoundPlace($change, 1), $since, null)->withOutcomes([new ReviewerOutcome($round.'-correctness', 'correctness', 'standard', $since)])]);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-01T10:30:00+00:00');
    }

    private function rendered(Headline $headline): Crawler
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler('<p id="board-headline">'.$twig->render(self::PARTIAL, ['headline' => $headline]).'</p>')->filter('#board-headline');
    }
}
