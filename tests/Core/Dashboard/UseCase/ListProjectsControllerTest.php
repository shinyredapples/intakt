<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\ProjectCard;
use Intakt\Core\Dashboard\Infrastructure\ProjectCards;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ListProjectsController::class)]
#[CoversClass(ProjectCards::class)]
#[CoversClass(ProjectCard::class)]
final class ListProjectsControllerTest extends DashboardPageTestCase
{
    public function testTheProjectListShowsACardForEveryProjectThatStartedAReview(): void
    {
        $this->ledger->startRoundOf('feat/z', 'round-1', 1);
        $this->ledger->startRoundOf('feat/a', 'round-2', 1);

        $crawler = $this->browser->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSame(['alpha'], $this->namesOn($crawler));
    }

    public function testACardCountsRunningAndJudgedReviewsOfItsOwnProjectOnly(): void
    {
        $this->ledger->startRound('round-1', 'zeta');
        $this->ledger->startRound('round-2', 'alpha');
        $this->ledger->judgeRound('round-2');
        $this->ledger->startRound('round-3', 'zeta');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame(['0', '2', '0', '0'], $this->figuresOf($crawler, 'zeta'));
        self::assertSame(['0', '0', '1', '0'], $this->figuresOf($crawler, 'alpha'));
    }

    public function testAFailingHeadMakesItsProjectFailingWithTheRedactedCause(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->failHeadBecause('alpha', 'pr-7', 'the checks timed out');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertStringContainsString('Failing: the checks timed out', $crawler->filter('[data-project="alpha"] .failing')->text());
        self::assertCount(1, $crawler->filter('[data-project="alpha"] .dot.broken'));
        self::assertCount(0, $crawler->filter('[data-project="alpha"] .waits'));
    }

    public function testAHandedOverHeadCountsAsWaitingOnTheOwner(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->failHead('alpha', 'pr-7', 1);
        $this->ledger->handOverHead('alpha', 'pr-7', 'a');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame('1 waits on you', $this->normalized($crawler->filter('[data-project="alpha"] .waits')->text()));
        self::assertCount(1, $crawler->filter('[data-project="alpha"] .waits .count.needs'));
    }

    public function testACardShowsTheTokensItsProjectUsed(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->useTokens('round-1', 1200, 300);

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame('1,500', $this->figuresOf($crawler, 'alpha')[0]);
    }

    public function testAProjectWhoseTiersDoNotResolveStillGetsItsCardWithTheProblem(): void
    {
        $this->ledger->startRound('round-1', 'unmapped');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-project="unmapped"] .tiers li'));
    }

    public function testALongProjectNameIsCutOnTheCardWithTheFullNameInTheTooltip(): void
    {
        $name = str_repeat('long-project-name-', 6);
        $this->ledger->startRound('round-1', $name);

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame($name, $crawler->filter('.pblock .plink')->attr('title'));
    }

    public function testAProjectNamedWithDigitsOnlyGetsItsOwnCard(): void
    {
        $this->ledger->startRound('round-1', '0');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame(['0'], $this->namesOn($crawler));
    }

    public function testEachCardLinksToTheReviewsOfItsProject(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame('/reviews?project=alpha', $crawler->filter('[data-project="alpha"] .plink')->attr('href'));
    }

    public function testThePageHeadNamesThePageAndSaysWhatItShows(): void
    {
        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame('Projects', $crawler->filter('.page-head h1')->text());
        self::assertSame('Every project at a glance: what it works on now, what it has used, and what waits on you.', $crawler->filter('.page-head .lead')->text());
    }

    public function testOnceAProjectHasACardThePageHeadLinksTheFirstRunPage(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $link = $this->browser->request('GET', '/projects')->filter('.page-head .actions a');

        self::assertSame(['/setup'], $link->each(static fn (Crawler $a): ?string => $a->attr('href')));
    }

    public function testWithoutAnyProjectThePageSaysSoAndLinksTheFirstRunPage(): void
    {
        $page = $this->browser->request('GET', '/projects');

        self::assertSelectorTextContains('main .empty', 'No project has started a review yet.');
        self::assertSame(['/setup'], $page->filter('main .empty a')->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertCount(0, $page->filter('.page-head a[href="/setup"]'));
    }

    public function testAProjectNameWithMarkupIsEscaped(): void
    {
        $this->ledger->startRound('round-1', '<b>bold</b>');

        $this->browser->request('GET', '/projects');

        self::assertSelectorNotExists('main b');
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', (string) $this->browser->getResponse()->getContent());
    }

    /**
     * @return list<string>
     */
    private function namesOn(Crawler $page): array
    {
        return $page->filter('.pblock')->each(static fn (Crawler $card): string => (string) $card->attr('data-project'));
    }

    // Read by position, because the card lists tokens, running, judged and failing in that order.
    /**
     * @return list<string>
     */
    private function figuresOf(Crawler $page, string $project): array
    {
        return $page->filter('[data-project="'.$project.'"] dd')->each(static fn (Crawler $dd): string => $dd->text());
    }

    private function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
