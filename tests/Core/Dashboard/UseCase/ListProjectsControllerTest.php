<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\DomCrawler\Crawler;

#[CoversClass(ListProjectsController::class)]
#[CoversClass(ProjectSummary::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
final class ListProjectsControllerTest extends DashboardPageTestCase
{
    public function testTheProjectListShowsEveryProjectThatStartedAReview(): void
    {
        $this->ledger->startRound('round-1', 'zeta');
        $this->ledger->startRound('round-2', 'alpha');
        $this->ledger->startRound('round-3', 'alpha');
        $this->ledger->judgeRound('round-2');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSame(['alpha', 'zeta'], $crawler->filter('.list .row .t')->each(static fn ($title): string => $title->text()));
        self::assertSame('2 review rounds, 1 judged.', $crawler->filter('.list .row .d')->first()->text());
    }

    public function testAProjectWithOneRoundSaysRoundInTheSingular(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame('1 review round, 0 judged.', $crawler->filter('.list .row .d')->text());
    }

    public function testThePageHeadNamesThePageAndSaysWhatItLists(): void
    {
        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame('Projects', $crawler->filter('.page-head h1')->text());
        self::assertSame('Every project that has started a review. Open one to see its reviews.', $crawler->filter('.page-head .lead')->text());
    }

    public function testEachProjectLinksToItsReviews(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertSame('/reviews?project=alpha', $crawler->filter('.list .row .t a')->attr('href'));
    }

    public function testOnceAReviewHasRunThePageHeadLinksTheFirstRunPage(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $link = $this->browser->request('GET', '/projects')->filter('.page-head .actions a.btn.secondary');

        self::assertSame(['/setup'], $link->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertSame('Set up Intakt', $link->text());
    }

    public function testOnceAProjectIsListedThePageHeadsPrimaryButtonAddsAProject(): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $primary = $this->browser->request('GET', '/projects')->filter('main .btn.primary');

        self::assertSame(['Add a project'], $primary->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('/projects/new', $primary->attr('href'));
    }

    public function testBeforeAProjectIsListedThePageOffersSetupAndNotAddAProject(): void
    {
        $page = $this->browser->request('GET', '/projects');

        self::assertCount(0, $page->filter('a[href="/projects/new"]'));
    }

    public function testBeforeAReviewHasRunThePageHeadLinksNoFirstRunPage(): void
    {
        $page = $this->browser->request('GET', '/projects');

        self::assertCount(0, $page->filter('.page-head a[href="/setup"]'));
    }

    public function testTheProjectListSaysSoWhenTheLedgerHasNoReviews(): void
    {
        $this->browser->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main .empty', 'No project has started a review yet.');
        self::assertSelectorNotExists('.list');
    }

    public function testTheEmptyProjectListHasTheFirstRunPageAsItsOneAction(): void
    {
        $action = $this->browser->request('GET', '/projects')->filter('main .empty a');

        self::assertSame(['/setup'], $action->each(static fn (Crawler $a): ?string => $a->attr('href')));
        self::assertSame('Set up Intakt', $action->text());
    }

    public function testAProjectNameWithMarkupIsEscaped(): void
    {
        $this->ledger->startRound('round-1', '<b>bold</b>');

        $this->browser->request('GET', '/projects');

        self::assertSelectorNotExists('main b');
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', (string) $this->browser->getResponse()->getContent());
    }
}
