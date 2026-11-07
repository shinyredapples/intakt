<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
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
        foreach (['/projects', '/reviews', '/reviews/round-1'] as $page) {
            yield $page.' on another name' => [$page, 'attacker.example:7780'];
            yield $page.' on another name without a port' => [$page, 'attacker.example'];
            yield $page.' on loopback without the port' => [$page, 'localhost'];
            yield $page.' on loopback with another port' => [$page, '127.0.0.1:8080'];
            yield $page.' on a name that starts like loopback' => [$page, 'localhost.attacker.example:7780'];
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function loopbackHosts(): iterable
    {
        foreach (['/projects', '/reviews', '/reviews/round-1'] as $page) {
            yield $page.' on localhost' => [$page, 'localhost:7780'];
            yield $page.' on IPv4 loopback' => [$page, '127.0.0.1:7780'];
            yield $page.' on IPv6 loopback' => [$page, '[::1]:7780'];
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

        new RefuseForeignHost(7780)($event);

        self::assertFalse($event->hasResponse());
    }
}
