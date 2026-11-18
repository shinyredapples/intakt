<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase;

use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\ReadAnswer;
use Intakt\Core\Review\Contract\ReadFinding;
use Intakt\Core\Review\Contract\SessionSummaries;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\AgentVendor;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

// From the scripted model's answer through LiveProgress to the update that reaches the hub, because that is the whole path the typed answer takes.
#[Group('persistence')]
#[CoversClass(MercureRoundUpdates::class)]
#[UsesClass(ReadAnswer::class)]
#[UsesClass(ReadFinding::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Fence::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveProgress::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(ReviewerStopping::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(StopRequests::class)]
#[UsesClass(StreamingFallbacks::class)]
final class ScriptedReviewPublishesAnswerTest extends WebTestCase
{
    /**
     * @var list<Update>
     */
    private array $published = [];

    public function testAScriptedReviewersJsonAnswerReachesTheHubAsItsTypedAnswerAndNeverAsJson(): void
    {
        $answer = VerdictDocument::fromTheDesign()->withFinding('file', 'src/Cart.php')->json();

        $data = $this->publishedWhenTheReviewerAnswers($answer);

        self::assertStringContainsString('<p class="answer-read">Verdict: changes</p>', $data);
        self::assertStringContainsString('src/Cart.php:12', $data);
        self::assertStringNotContainsString('{"', $data);
        self::assertStringNotContainsString('"verdict"', $data);
    }

    public function testAScriptedReviewersUnreadableAnswerReachesTheHubAsOneMarkerAndNeverAsText(): void
    {
        $data = $this->publishedWhenTheReviewerAnswers('{"verdict": "chang');

        self::assertSame(1, substr_count($data, 'answer-unreadable'));
        self::assertStringNotContainsString('chang', $data);
    }

    private function publishedWhenTheReviewerAnswers(string $answer): string
    {
        $fixture = new RoundFixture(self::getContainer());
        $fixture->start(1);
        $message = $fixture->bus->messages[0];
        $hub = new MockHub('http://localhost/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id-'.count($this->published);
        });
        $updates = new MercureRoundUpdates(new MercureLiveUpdates($hub, new NullLogger()), self::getContainer()->get(Environment::class), self::createStub(SessionSummaries::class), self::getContainer()->get(LedgerReviewPages::class), new LockFactory(new InMemoryStore()), new NullLogger());
        $platform = MockPlatformFactory::createPlatform(static fn (): string => $answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress($updates), new ReviewerStopping(new StopRequests($fixture->ledger(), $fixture->ledgerPayloads())));
        $vendor = new AgentVendor(new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), $runs), $platform);

        $fixture->deliver($message, $vendor);

        return implode("\n", array_map(static fn (Update $update): string => $update->getData(), $this->published));
    }
}
