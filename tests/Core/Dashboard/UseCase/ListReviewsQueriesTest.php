<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Profiler\Profile;

// Measured by the profiler on the real SQLite ledger, because the page's in-memory ledger double runs no query and a count read from the code is a guess (#2721, D2).
#[Group('persistence')]
#[CoversClass(ListReviewsController::class)]
final class ListReviewsQueriesTest extends WebTestCase
{
    private const int ROUNDS = 3;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        $fixture = new RoundFixture(self::getContainer());

        for ($change = 1; $change <= self::ROUNDS; ++$change) {
            $fixture->startFor('feat/change-'.$change, $fixture->bus, 2);
        }

        // The seeding ran before any request, so nothing reset the query log yet; a worker starts each request with an empty one.
        self::getContainer()->get('doctrine.debug_data_holder')->reset();
    }

    public function testTheReviewsPageOfThreeRoundsReadsTheDatabaseAFixedNumberOfTimes(): void
    {
        $this->browser->enableProfiler();

        $this->browser->request('GET', '/reviews');

        self::assertResponseIsSuccessful();
        // Six page reads plus three per unjudged round, because each one asks for its judging failures on its own; reading them once per page is a follow-up of #2721.
        self::assertSame(15, $this->queries()->getQueryCount());
    }

    public function testARequestThatDoesNotAskForTheProfilerCollectsNoProfile(): void
    {
        $this->browser->request('GET', '/reviews');

        self::assertResponseIsSuccessful();
        self::assertNull($this->browser->getProfile());
    }

    private function queries(): DoctrineDataCollector
    {
        $profile = $this->browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        return $collector;
    }
}
