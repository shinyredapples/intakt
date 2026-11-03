<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class NoPollingTest extends DashboardPageTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        yield 'project list' => ['/projects'];
        yield 'review list' => ['/reviews'];
        yield 'review list of one project' => ['/reviews?project=alpha'];
    }

    public function testTheReviewPageWithTheStartButtonHoldsOnlyAPlainForm(): void
    {
        $this->ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);

        $this->browser->request('GET', '/reviews/round-1');

        $page = strtolower((string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString('<form method="post"', $page);

        foreach (['http-equiv', 'setinterval', 'settimeout', 'fetch(', 'xmlhttprequest', 'websocket'] as $polling) {
            self::assertStringNotContainsString($polling, $page);
        }
    }

    #[DataProvider('pages')]
    public function testNoDashboardPageContainsAScriptARefreshTagOrAPollingCall(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->browser->request('GET', $path);

        $page = strtolower((string) $this->browser->getResponse()->getContent());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<table', $page);

        foreach (['<script', 'http-equiv', 'setinterval', 'settimeout', 'fetch(', 'xmlhttprequest', 'eventsource', 'websocket'] as $polling) {
            self::assertStringNotContainsString($polling, $page);
        }
    }
}
