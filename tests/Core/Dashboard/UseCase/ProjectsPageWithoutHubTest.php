<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Domain\ProjectCard;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\ProjectCards;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

// The test environment has no hub key (MERCURE_JWT_SECRET is empty), so no listener can be authorized, as when the hub is not set up.
#[CoversClass(ListProjectsController::class)]
#[UsesClass(LivePageRendering::class)]
#[UsesClass(ProjectCards::class)]
#[UsesClass(ProjectCard::class)]
final class ProjectsPageWithoutHubTest extends DashboardPageTestCase
{
    public function testThePageRendersEveryCardWhenTheHubCannotAuthorizeAListener(): void
    {
        $this->ledger->startRound('round-1', 'alpha');
        $this->ledger->startRound('round-2', 'beta');

        $crawler = $this->browser->request('GET', '/projects');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('turbo-mercure-stream-source'));
        self::assertCount(2, $crawler->filter('#project-cards .pblock'));
    }
}
