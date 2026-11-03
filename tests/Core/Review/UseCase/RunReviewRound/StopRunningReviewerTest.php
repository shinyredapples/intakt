<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Dashboard\UseCase\StopReviewer\ReviewerStops;
use Intakt\Core\Dashboard\UseCase\StopReviewer\StopReviewerController;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\PromptCut;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

// A worker reviewing on a scripted platform and a person pressing Stop on the review page of the same install, so the stop is proven from the button to the cancelled execution and back to the page.
#[Group('persistence')]
#[CoversClass(StopReviewerController::class)]
#[CoversClass(ReviewerStops::class)]
#[CoversClass(ReviewerStopping::class)]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerRuns::class)]
final class StopRunningReviewerTest extends WebTestCase
{
    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private KernelBrowser $browser;
    private RoundFixture $fixture;
    private string $round;
    private string $reviewer;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testStopPressedWhileTheReviewerWorksCancelsItsExecutionAndThePageShowsItStopped(): void
    {
        $this->round = $this->fixture->start(1)->id()->toRfc4122();
        $message = $this->fixture->bus->messages[0];
        $this->reviewer = $message->reviewerId;

        $this->fixture->deliver($message, new ReviewerOnPlatform($this->reviewerAgent(), $this->platformPressingStop()));

        $stored = $this->fixture->reviewer($this->reviewer);
        self::assertSame(1, $this->calls, 'The execution went on after the stop.');
        self::assertSame(ReviewerState::Verdict, $stored->state());
        self::assertStringContainsString('"verdict":"blocked"', (string) $stored->verdictJson());
        self::assertStringContainsString('"id":"'.Verdict::STOPPED.'"', (string) $stored->verdictJson());
        self::assertSame([LedgerEventKind::ReviewerStopRequested, LedgerEventKind::ReviewerStopped], array_values(array_filter($this->fixture->ledgerKinds(), static fn (LedgerEventKind $kind): bool => in_array($kind, [LedgerEventKind::ReviewerStopRequested, LedgerEventKind::ReviewerStopped], true))));
        $page = $this->browser->request('GET', '/reviews/'.$this->round);
        self::assertSelectorTextSame('#reviewer-'.$this->reviewer.'-state', 'stopped');
        self::assertCount(0, $page->filter('form.stop-reviewer'));
    }

    // The person presses Stop on the page while the model answers its first call, which asks for a tool, so the progress of that tool call is the first the worker reports after the press.
    private function platformPressingStop(): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(function (): string|ToolCallResult {
            if (1 < ++$this->calls) {
                return self::APPROVE;
            }

            $this->pressStop();

            return new ToolCallResult([new ToolCall('1', 'read_file', ['path' => 'missing.txt'])]);
        }, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
    }

    private function pressStop(): void
    {
        $page = $this->browser->request('GET', '/reviews/'.$this->round);
        self::assertSelectorTextSame('#reviewer-'.$this->reviewer.'-state', 'running');
        $token = $page->filter('#reviewer-'.$this->reviewer.'-stop input[name=_token]')->attr('value');

        $this->browser->request('POST', '/reviews/'.$this->round.'/reviewers/'.$this->reviewer.'/stop', ['_token' => $token]);

        self::assertResponseStatusCodeSame(303);
    }

    private function reviewerAgent(): ReviewerAgent
    {
        $updates = $this->fixture->updates;
        $stopping = new ReviewerStopping($this->fixture->ledger(), $updates, new MockClock(), new NullLogger());
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress($updates, new MockClock()), $stopping);

        return new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), $runs);
    }
}
