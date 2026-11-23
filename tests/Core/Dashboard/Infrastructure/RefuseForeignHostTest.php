<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\DashboardHost;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\RefuseForeignHost;
use Intakt\Core\Dashboard\UseCase\ListProjects\ListProjectsController;
use Intakt\Core\Dashboard\UseCase\ListReviews\ListReviewsController;
use Intakt\Core\Dashboard\UseCase\ShowReview\ShowReviewController;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardPageTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(RefuseForeignHost::class)]
#[UsesClass(DashboardHost::class)]
#[UsesClass(LedgerPayload::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(ListProjectsController::class)]
#[UsesClass(ListReviewsController::class)]
#[UsesClass(ProjectSummary::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(ShowReviewController::class)]
#[UsesClass(TokenFigures::class)]
final class RefuseForeignHostTest extends DashboardPageTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function foreignHosts(): iterable
    {
        foreach (['/projects', '/reviews', '/reviews/round-1', '/search'] as $page) {
            foreach (DashboardHostTest::FOREIGN_HOSTS as $name => $host) {
                yield $page.' on '.$name => [$page, $host];
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function loopbackHosts(): iterable
    {
        foreach (['/projects', '/reviews', '/reviews/round-1'] as $page) {
            foreach (DashboardHostTest::LOOPBACK_HOSTS as $name => $host) {
                yield $page.' on '.$name => [$page, $host];
            }
        }
    }

    #[DataProvider('foreignHosts')]
    public function testADashboardPageRefusesAHostThatDoesNotNameTheDashboard(string $page, string $host): void
    {
        $this->ledger->startRound('round-1', 'private-project');

        $this->browser->request('GET', $page, server: ['HTTP_HOST' => $host]);

        self::assertResponseStatusCodeSame(400);
        self::assertStringNotContainsString('private-project', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheRefusalNamesTheAddressTheDashboardAnswersAndHowToReachItFromAnotherDevice(): void
    {
        $page = $this->browser->request('GET', '/projects', server: ['HTTP_HOST' => '192.0.2.10:7780']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Open the dashboard on localhost', $page->filter('h1')->text());
        self::assertStringContainsString('Intakt answers only http://localhost:7780, on the machine it runs on.', $page->filter('.lead')->text());
        self::assertSame('ssh -L 7780:localhost:7780 you@your-server', $page->filter('pre code')->text());
    }

    #[DataProvider('loopbackHosts')]
    public function testADashboardPageAnswersALoopbackNameWithTheConfiguredPort(string $page, string $host): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->browser->request('GET', $page, server: ['HTTP_HOST' => $host]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'alpha');
    }

    public function testARouteOfAnotherModuleIsLeftAlone(): void
    {
        $request = Request::create('http://attacker.example/elsewhere');
        $request->attributes->set('_route', 'mcp_list_tools');
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);

        new RefuseForeignHost(new DashboardHost(7780))($event);

        self::assertFalse($event->hasResponse());
    }
}
