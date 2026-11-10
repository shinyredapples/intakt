<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversNothing]
final class NoPollingTest extends DashboardPageTestCase
{
    // The list pages listen to the shell topic only when the hub can authorize a listener, which the test environment cannot (no key), so they hold just the inline scripts here (the third imports the danger buttons' script, which only answers clicks); the stream source and its import map are asserted in ShellLiveUpdatesTest.
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
    public function testADashboardListPageHoldsOnlyTheThemeAndShellScriptsAndNoRefreshTagOrPollingCall(string $path): void
    {
        $this->ledger->startRound('round-1', 'alpha');

        $this->browser->request('GET', $path);

        $page = strtolower((string) $this->browser->getResponse()->getContent());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-project="alpha"', $page);

        self::assertSame(3, substr_count($page, '<script'));
        self::assertSame(2, substr_count($page, '<script>(() => {'));
        self::assertSame(1, substr_count($page, '<script type="module">import { holdbackdangerbuttons } from \'/assets/arm-'));
        self::assertStringContainsString('prefers-color-scheme', $page);
        self::assertStringContainsString('data-rail-toggle', $page);
        foreach (['http-equiv', 'setinterval', 'settimeout', 'fetch(', 'xmlhttprequest', 'eventsource', 'websocket', '<script src'] as $polling) {
            self::assertStringNotContainsString($polling, $page);
        }
    }
}
