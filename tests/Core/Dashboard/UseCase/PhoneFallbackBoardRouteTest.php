<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\UseCase\ShowError\ShowErrorController;
use Intakt\Core\Ledger\Contract\Ledger;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Twig\Environment;

#[CoversClass(ShowErrorController::class)]
final class PhoneFallbackBoardRouteTest extends DashboardPageTestCase
{
    public function testThePlainErrorPagesBoardLinkOpensTheBoardOnAPhone(): void
    {
        $twig = self::createStub(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template): string {
            if ('dashboard/error.html.twig' === $template) {
                throw new RuntimeException('Error template unavailable');
            }

            return '<h1>Board</h1>';
        });
        self::getContainer()->set('twig', $twig);
        $this->browser->disableReboot();
        $this->browser->setServerParameter('HTTP_USER_AGENT', 'Mobile');

        $missing = $this->browser->request('GET', '/missing-page');
        self::assertResponseStatusCodeSame(404);
        $this->browser->click($missing->selectLink('Back to the board')->link());

        self::assertResponseStatusCodeSame(200);
    }

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        self::getContainer()->set(Ledger::class, new DashboardLedger());
    }
}
