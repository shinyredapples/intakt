<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Generator;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UnstreamedModelCatalog;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Infrastructure\Repository\ReviewCheckout;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Assert;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\TraceableToolbox;
use Symfony\AI\AiBundle\Profiler\DataCollector;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TraceablePlatform;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\UuidV7;

// Shared by the tests of the reviewer agent's wiring, because each scripts a real ReviewerAgent on the application's dispatcher the same way.
final class ScriptedReviewRun
{
    public const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private const string COLLECTOR = 'test.reviewer_verdict.ai_data_collector';

    public ReviewCheckout $fixture;

    public MessageStoreInterface $conversations;

    public UuidV7 $conversation;

    public UuidV7 $round;

    public bool $inARound = true;

    // Streamed before the first tool call of the script, because a reviewer often says what it reads next and the live view shows that narration.
    /**
     * @var list<string>
     */
    public array $narration = [];

    public ModelCatalogInterface $catalog;

    public InMemoryLedger $ledger;

    // A count, not a flag, because a provider may accept a streamed round and refuse a later one, which must not count as unable to stream; null never refuses.
    public ?int $streamsAccepted = null;

    public RecordedRoundUpdates $updates;

    /**
     * @var list<Finding>
     */
    public array $open = [];

    public int $roundNumber = 1;

    /**
     * @var list<string>
     */
    public array $sent = [];

    public string $diff = 'diff --git a/a b/a';

    public function __construct(private readonly TestContainer $container)
    {
        $this->fixture = new ReviewCheckout();
        $this->conversations = new InMemoryMessageStore();
        $this->conversation = new UuidV7();
        $this->round = new UuidV7();
        $this->updates = new RecordedRoundUpdates();
        $this->catalog = new UnstreamedModelCatalog();
        $this->ledger = new InMemoryLedger();
    }

    // Because the toolbox is built per review and the platform per test, the collector is built from this run's platform instead of a profiled request.
    public static function collectorIn(TestContainer $container): DataCollector
    {
        Assert::assertTrue($container->has(self::COLLECTOR), 'No scripted review was made.');
        $collector = $container->get(self::COLLECTOR);
        Assert::assertInstanceOf(DataCollector::class, $collector);

        return $collector;
    }

    // The kernel stays booted for the class, so the services and the collector of this run are reset instead.
    public function end(): void
    {
        $this->fixture->remove();
        $this->container->get('services_resetter')->reset();
        $this->container->set(self::COLLECTOR, null);
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    // Because the script asks for one tool call per round, the answer follows the last call.
    public function review(string $task, array $toolCalls, string|ResultInterface $answer): Verdict
    {
        return $this->reviewRounds($task, array_map(static fn (ToolCall $call): array => [$call], $toolCalls), $answer);
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    public function refused(string $task, array $toolCalls, string|ResultInterface $answer): VerdictUnreadable
    {
        try {
            $this->review($task, $toolCalls, $answer);
        } catch (VerdictUnreadable $refused) {
            return $refused;
        }

        Assert::fail('The answer was not refused as unreadable.');
    }

    /**
     * @param list<list<ToolCall>> $rounds
     */
    // Because one model answer can ask for several tools at once, a round is a list of calls, and the verdict follows the last round.
    public function reviewRounds(string $task, array $rounds, string|ResultInterface $answer): Verdict
    {
        $script = function (mixed $model, mixed $input, array $options) use (&$rounds, $answer): string|ResultInterface {
            $this->refuseToStreamIfConfigured($options);
            $this->sent[] = json_encode($input, JSON_THROW_ON_ERROR);
            $next = array_shift($rounds);

            return $this->streamedWhenAsked(null === $next ? $answer : new ToolCallResult($next), $options);
        };
        $mock = MockPlatformFactory::createPlatform($script, $this->catalog, eventDispatcher: ScriptedReviewer::in($this->container)->dispatcher);
        // Traced only when the call is not streamed, because the tracing platform wraps a stream in a result without the listeners that assemble the verdict.
        $platform = $this->catalog instanceof UnstreamedModelCatalog ? new TraceablePlatform($mock) : $mock;
        $toolbox = new TraceableToolbox(new ReviewerToolboxFactory()->inCheckout($this->fixture->checkout));
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), static fn (): TraceableToolbox => $toolbox, new ReviewerRuns($this->conversations, new StreamingFallbacks($this->ledger, new NullLogger()), new LiveProgress($this->updates), new ReviewerStopping(new StopRequests($this->ledger, new LedgerPayloads(StoredSerializerFactory::create())))));

        // Collected in finally, so a case whose answer is refused can still count the calls and tool calls it made.
        try {
            return $reviewer->review(new ReviewRequest($this->fixture->checkout, new ReviewBrief(new ChangeUnderReview('alpha', $task, $this->diff), $this->open, $this->roundNumber), new ReviewerConversation('You review for correctness.', $this->conversation, $this->inARound ? $this->round : null)), $platform, new ResolvedModel(Provider::Anthropic, 'reviewer-model'));
        } finally {
            $collector = new DataCollector($platform instanceof TraceablePlatform ? [$platform] : [], [$toolbox], [$toolbox], [], [], []);
            $collector->lateCollect();
            $this->container->set(self::COLLECTOR, $collector);
        }
    }

    public static function streamed(string ...$parts): StreamResult
    {
        return new StreamResult((static function () use ($parts): Generator {
            foreach ($parts as $part) {
                yield new TextDelta($part);
            }
        })());
    }

    public function seen(string $kind, string $what): string
    {
        return sprintf('%s %s %s', $kind, $this->conversation->toRfc4122(), $what);
    }

    public function openFinding(string $id, ?string $symbol = null): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, $symbol, 'Text '.$id, FixableBy::Builder, false, null, null);
    }

    /**
     * @param array<mixed> $options
     */
    private function refuseToStreamIfConfigured(array $options): void
    {
        if (null === $this->streamsAccepted || true !== ($options['stream'] ?? false)) {
            return;
        }

        if (0 === $this->streamsAccepted) {
            throw new BadRequestException('This request is refused.');
        }

        --$this->streamsAccepted;
    }

    // Like a provider that answers in one piece unless the request asks for a stream.
    /**
     * @param array<mixed> $options
     */
    private function streamedWhenAsked(string|ResultInterface $result, array $options): string|ResultInterface
    {
        if (true !== ($options['stream'] ?? false) || $result instanceof StreamResult) {
            return $result;
        }

        if ($result instanceof ToolCallResult) {
            $narration = $this->narration;
            $this->narration = [];

            return new StreamResult((static function () use ($result, $narration): Generator {
                foreach ($narration as $part) {
                    yield new TextDelta($part);
                }

                yield new ToolCallComplete($result->getContent());
            })());
        }

        $text = $result instanceof TextResult ? $result->getContent() : $result;

        return is_string($text) ? self::streamed(...str_split($text, 40)) : $result;
    }
}
