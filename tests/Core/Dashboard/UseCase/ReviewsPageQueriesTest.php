<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;

// Measured with the profiler on the real Doctrine ledger, because a page that reads the ledger once per round shows up only in the number of its queries.
#[Group('persistence')]
#[CoversClass(ListReviewsController::class)]
#[UsesClass(LedgerReviews::class)]
final class ReviewsPageQueriesTest extends WebTestCase
{
    private KernelBrowser $browser;

    private RoundFixture $fixture;

    // Ten, as for one round, because the endings of the unjudged rounds come from one lookup per page read and the shell reads the owner notices once for the You count, whatever the rounds.
    public function testTheReviewsPageWithThreeRoundsRunsTenQueries(): void
    {
        $this->givenRounds(3);

        self::assertSame(10, $this->queriesOfTheReviewsPage());
    }

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        self::clockAt('2026-10-30 12:00:00 UTC');
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function givenRounds(int $count): void
    {
        foreach (range(1, $count) as $change) {
            $this->fixture->startFor('feat/change-'.$change, $this->fixture->bus, 1);
        }
    }

    private function queriesOfTheReviewsPage(): int
    {
        // The kernel is not rebooted before the request, so without this the profile would also count the queries that wrote the rounds.
        $profiler = self::getContainer()->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $profiler->reset();
        $this->browser->enableProfiler();
        $this->browser->request('GET', '/reviews');
        self::assertResponseIsSuccessful();
        $profile = $this->browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $queries = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $queries);

        return $queries->getQueryCount();
    }
}
