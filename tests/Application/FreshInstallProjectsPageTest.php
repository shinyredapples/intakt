<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use Intakt\Core\Ledger\Infrastructure\DoctrineLedger;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

#[Group('persistence')]
#[CoversClass(ListProjectsController::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(ProjectSummary::class)]
#[UsesClass(DoctrineLedger::class)]
final class FreshInstallProjectsPageTest extends WebTestCase
{
    public function testTheProjectsPageOfAFreshMigratedDatabaseSaysNoProjectHasStartedAReview(): void
    {
        $browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        EmptyDatabase::in(self::getContainer());
        $this->migrate();

        $browser->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main .empty', 'No project has started a review yet.');
    }

    private function migrate(): void
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);

        $console->run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());
    }
}
