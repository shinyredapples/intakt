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

    public function testTheWaitingCountInTheBoardHeadlineCarriesTheNeedsYouStyle(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking([]), [$this->review('round-7', '7'), $this->review('round-9', '9')], 2));

        self::assertSame('Nothing is running. 2 need you: see what.', $line->text());
        self::assertSame(['2'], $line->filter('.count.needs')->each(static fn (Crawler $count): string => $count->text()));
        self::assertSame('/reviews/round-7', $line->filter('a')->attr('href'));
    }

    public function testTheBoardHeadlineWithoutWaitingWorkShowsNoNeedsYouMark(): void
    {
        $running = $this->review('round-3', '3');

        $line = $this->rendered(new Headline(new WhoIsWorking([$running]), [], 1));

        self::assertSame('The Code reviewers are reviewing change 3 of sample. The Code reviewers are reviewing 1 change. Nothing needs you.', $line->text());
        self::assertCount(0, $line->filter('.count, a'));
    }

    public function testARunningChangeIsNamedOnWideScreensAndCountedOnPhones(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking([$this->review('round-3', '3'), $this->review('round-5', '5')]), [], 2));

        self::assertSame('The Code reviewers are reviewing change 3 of sample and change 5 of sample.', $line->filter('.headline-long')->text());
        self::assertSame('The Code reviewers are reviewing 2 changes.', $line->filter('.headline-short')->text());
    }

    public function testBothOpeningsComeBeforeTheSingularWaitingCountAndItsLink(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking([$this->review('round-3', '3')]), [$this->review('round-7', '7')], 2));

        self::assertSame('The Code reviewers are reviewing change 3 of sample. The Code reviewers are reviewing 1 change. 1 needs you: see what.', $line->text());
        self::assertSame(['1'], $line->filter('.count.needs')->each(static fn (Crawler $count): string => $count->text()));
        self::assertSame('/reviews/round-7', $line->filter('a')->attr('href'));
    }

    public function testWithNothingRunningTheHeadlineHasOneOpening(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking([]), [], 1));

        self::assertSame('Nothing is running. Nothing needs you.', $line->text());
        self::assertCount(0, $line->filter('.headline-long, .headline-short'));
    }

    public function testAFreshInstallsHeadlineIsItsOpeningAlone(): void
    {
        $line = $this->rendered(new Headline(new WhoIsWorking([]), [], 0));

        self::assertSame('Nothing is running yet. Start the first review.', $line->text());
        self::assertCount(0, $line->filter('.count, a'));
    }

    private function rendered(Headline $headline): Crawler
    {
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return new Crawler('<p id="board-headline">'.$twig->render(self::PARTIAL, ['headline' => $headline]).'</p>')->filter('#board-headline');
    }

    private function review(string $round, string $change): Review
    {
        $since = new DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $start = new RoundStart($round, 'sample', 2);

        return new Review([new ReviewedRound($start, new RoundPlace($change, 1), $since, null)->withOutcomes([new ReviewerOutcome($round.'-correctness', 'correctness', 'standard', $since)])]);
    }
}
