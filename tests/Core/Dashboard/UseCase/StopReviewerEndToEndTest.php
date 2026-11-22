<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Closure;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\UseCase\StopReview\StopReviewController;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\UseCase\RunReviewRound\LiveRoundProgress;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\AgentVendor;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

// The stop is pressed on the real page while a scripted reviewer works, so the order is forced by the script and nothing is seeded by hand.
#[Group('persistence')]
#[CoversClass(StopReviewController::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerStopping::class)]
#[UsesClass(StopRequests::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LiveRoundProgress::class)]
final class StopReviewerEndToEndTest extends WebTestCase
{
    private KernelBrowser $browser;

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        // The page reads the round's end from the clock, so it must share the fixture's time or the round is long overdue.
        self::clockAt('2026-10-30 12:00:00 UTC');
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAStopPostedWhileAScriptedReviewerWorksCancelsItAndThePageShowsStoppedWithNoForm(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $round = $this->fixture->reviewer($message->reviewerId)->round()->id()->toRfc4122();
        $page = '/reviews/'.$round.'/record';
        $running = [];

        $script = function () use ($message, $round, $page, &$running): ToolCallResult {
            $crawler = $this->browser->request('GET', $page);
            $running = [$crawler->filter('td[id$="-state"]')->text(), $crawler->filter('form.stop-reviewer')->count()];
            $token = $crawler->filter('form.stop-reviewer input[name=_token]')->attr('value');
            $this->browser->request('POST', sprintf('/reviews/%s/reviewers/%s/stop', $round, $message->reviewerId), ['_token' => $token]);
            self::assertResponseStatusCodeSame(303);

            return new ToolCallResult([new ToolCall('1', 'read_file', ['path' => 'composer.json'])]);
        };

        $this->fixture->deliver($message, $this->agentScripted($script));

        self::assertSame(['running', 1], $running);
        self::assertStringContainsString('"id":"reviewer-stopped"', (string) $this->fixture->reviewer($message->reviewerId)->verdictJson());
        self::assertSame(
            [LedgerEventKind::ReviewerStopRequested, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerStopped],
            array_values(array_filter($this->fixture->ledgerKinds(), static fn (LedgerEventKind $kind): bool => in_array($kind, [LedgerEventKind::ReviewerStopRequested, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerStopped], true))),
        );
        self::assertContains('stopped '.$message->reviewerId, $this->fixture->updates->seen);
        self::assertSame([], $this->fixture->updates->answers);
        self::assertSame([], array_values(array_filter($this->fixture->updates->seen, static fn (string $line): bool => str_starts_with($line, 'answer '))));

        $crawler = $this->browser->request('GET', $page);

        self::assertSame('stopped', $crawler->filter('td[id$="-state"]')->text());
        self::assertCount(0, $crawler->filter('td[id$="-state"] .dot'));
        self::assertCount(0, $crawler->filter('form.stop-reviewer'));
    }

    private function agentScripted(Closure $script): AgentVendor
    {
        $platform = MockPlatformFactory::createPlatform($script, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress($this->fixture->updates), new ReviewerStopping(new StopRequests($this->fixture->ledger(), $this->fixture->ledgerPayloads())));

        return new AgentVendor(new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), $runs), $platform);
    }
}
