<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ConversationAlreadyStarted;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\TrackedFinding;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\Repository\DenyPathsOutsideCheckout;
use Intakt\Core\Review\Infrastructure\Repository\ReadFile;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StrictVerdictConverter;
use Intakt\Core\Review\Infrastructure\StrictVerdictSubscriber;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Agent\Exception\ConflictException;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\AiBundle\Profiler\DataCollector;
use Symfony\AI\AiBundle\Test\AiAssertionsTrait;
use Symfony\AI\Platform\Bridge\Anthropic\Factory;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\TraceablePlatform;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(ReviewerAgent::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(StrictVerdictConverter::class)]
#[UsesClass(StrictVerdictSubscriber::class)]
#[UsesClass(MalformedToolCalls::class)]
#[UsesClass(Fence::class)]
#[UsesClass(ConversationAlreadyStarted::class)]
#[UsesClass(ReviewRequest::class)]
#[UsesClass(ReviewerConversation::class)]
#[UsesClass(ReviewBrief::class)]
#[UsesClass(Checkout::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(FindingLedger::class)]
#[UsesClass(TrackedFinding::class)]
#[UsesClass(VerdictParser::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReadFile::class)]
#[UsesClass(DenyPathsOutsideCheckout::class)]
final class ReviewerAgentTest extends WebTestCase
{
    use AiAssertionsTrait;
    use ScriptedReviews;

    /**
     * @var list<string>
     */
    private array $requests = [];

    public function testTheAgentReadsAFileAtTheHeadAndReturnsTheVerdictTheModelAnswered(): void
    {
        $this->open = [$this->openFinding('F1'), $this->openFinding('F2')];
        $verdict = $this->review('Add a cart.', [new ToolCall('1', 'read_file', ['path' => 'src/Cart.php'])], VerdictDocument::fromTheDesign()->json());

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(['F1', 'F2'], $verdict->resolved);
        self::assertPlatformCallCount(2);
        self::assertToolCalled('read_file');
        self::assertToolCallCount(1);
        self::assertStringContainsString('final class Cart', $this->sent[1]);
    }

    public function testAVerdictThatResolvesAnIdThatIsNotOpenIsRejectedAsInvalidOutput(): void
    {
        $this->open = [$this->openFinding('F1')];

        $verdict = $this->review('Add a cart.', [], '{"verdict":"approve","findings":[],"resolved":["F9"],"follow_ups":[]}');

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
    }

    public function testARepeatWithAnIdThatIsNotOpenIsRejectedAsInvalidOutput(): void
    {
        $this->open = [$this->openFinding('F1')];

        $verdict = $this->review('Add a cart.', [], VerdictDocument::fromTheDesign()->with('resolved', [])->withFinding('id', 'F7')->withFinding('repeat', true)->json());

        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
    }

    public function testAVerdictThatMarksTheSameIdResolvedAndRepeatIsRejectedAsInvalidOutput(): void
    {
        $this->open = [$this->openFinding('F3')];

        $verdict = $this->review('Add a cart.', [], VerdictDocument::fromTheDesign()->with('resolved', ['F3'])->withFinding('repeat', true)->json());

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
        self::assertStringContainsString('both resolved and repeat: F3', $verdict->findings[0]->text);
    }

    public function testARepeatWithAnOpenIdIsAccepted(): void
    {
        $this->open = [$this->openFinding('F3')];

        $verdict = $this->review('Add a cart.', [], VerdictDocument::fromTheDesign()->with('resolved', [])->withFinding('repeat', true)->json());

        self::assertSame('F3', $verdict->findings[0]->id);
    }

    public function testAScriptedTwoRoundReviewShowsTheOpenFindingsAndClosesTheResolvedOne(): void
    {
        $ledger = new FindingLedger();
        $ledger->recordVerdict(1, new Verdict(Decision::Changes, [$this->openFinding('a'), $this->openFinding('b')], [], []));
        $this->open = $ledger->openForReviewers();

        $verdict = $this->review('Add a cart.', [], '{"verdict":"changes","findings":[{"id":"F2","severity":"P2","file":"src/A.php","line":3,"symbol":null,"text":"Still there","fixable_by":"builder","repeat":true,"requirement":null,"evidence":null}],"resolved":["F1"],"follow_ups":[]}');
        $ledger->recordVerdict(2, $verdict);

        self::assertStringContainsString('F1 [P2] src\/A.php:3', $this->sent[0]);
        self::assertSame(['F2'], array_map(static fn (TrackedFinding $tracked): string => $tracked->id, $ledger->open()));
        self::assertSame([1, 2], $ledger->all()[1]->rounds);
    }

    public function testTheConversationOfTheRoundIsKeptInTheMessageStore(): void
    {
        $this->review('Add a cart.', [new ToolCall('1', 'read_file', ['path' => 'src/Cart.php'])], self::APPROVE);

        $kept = $this->conversations->load($this->conversation);

        self::assertSame(['system', 'user', 'assistant', 'tool', 'assistant'], $this->rolesIn($kept));
        self::assertCount(0, $this->conversations->load(new UuidV7()));
    }

    public function testAnotherReviewerOrRoundStartsItsOwnConversationAndLeavesTheEarlierOneAlone(): void
    {
        $this->review('Add a cart.', [], self::APPROVE);
        $first = $this->conversation;
        $this->conversation = new UuidV7();

        $this->review('Add a cart.', [], self::APPROVE);

        self::assertCount(3, $this->conversations->load($first));
        self::assertCount(3, $this->conversations->load($this->conversation));
    }

    public function testASecondReviewUnderAConversationAlreadyStartedIsRefusedAndTheFirstKeepsOnlyItsOwnPrompt(): void
    {
        $this->review('Add a cart.', [], self::APPROVE);

        try {
            $this->review('Add a cart.', [], self::APPROVE);
            self::fail('A second review must not continue the conversation of the first.');
        } catch (ConversationAlreadyStarted) {
            // Expected, because the first review started the conversation.
        }

        self::assertSame(['system', 'user', 'assistant'], $this->rolesIn($this->conversations->load($this->conversation)));
        self::assertCount(1, $this->sent);
    }

    public function testAReviewWhoseConversationAnotherReviewClaimedAtTheSameTimeIsRefusedBeforeTheModelIsAsked(): void
    {
        $store = self::createStub(MessageStoreInterface::class);
        $store->method('save')->willThrowException(new ConflictException('Another review inserted the conversation first.'));
        $this->conversations = $store;

        try {
            $this->review('Add a cart.', [], self::APPROVE);
            self::fail('A review must not run on a conversation another review claimed.');
        } catch (ConversationAlreadyStarted) {
            // Expected, because the store refused the first messages.
        }

        self::assertSame([], $this->sent);
    }

    public function testAWellFormedReviewKeepsTheApprovalTheModelAnswered(): void
    {
        $verdict = $this->review('Add a cart.', [new ToolCall('1', 'read_file', ['path' => 'src/Cart.php'])], self::APPROVE);

        self::assertSame(Decision::Approve, $verdict->verdict);
    }

    public function testACallOutsideTheRepositoryIsRefusedAndTheReviewStillEndsInTheVerdictTheModelAnswered(): void
    {
        $verdict = $this->review('Add a cart.', [new ToolCall('1', 'read_file', ['path' => '../outside/secret.txt'])], self::APPROVE);

        self::assertSame(Decision::Approve, $verdict->verdict);
        self::assertToolCalled('read_file');
        self::assertStringContainsString('outside the repository', $this->sent[1]);
        self::assertStringNotContainsString('outside secret', $this->sent[1]);
    }

    public function testAReviewerThatStillAsksForToolsAfterThirtyRoundsEndsAsChangesInsteadOfCrashing(): void
    {
        $forever = array_fill(0, 40, new ToolCall('1', 'read_file', ['path' => 'src/Cart.php']));

        $verdict = $this->review('Add a cart.', $forever, self::APPROVE);

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
        self::assertStringContainsString('still asking for tools after 30 rounds', $verdict->findings[0]->text);
        self::assertPlatformCallCount(31);
    }

    public function testARoundThatAsksForMoreThanThirtyFilesCountsAsOneRoundOfTheCap(): void
    {
        $batch = array_map(static fn (int $id): ToolCall => new ToolCall((string) $id, 'read_file', ['path' => 'src/Cart.php']), range(1, 35));

        $verdict = $this->reviewRounds('Add a cart.', [$batch], self::APPROVE);

        self::assertSame(Decision::Approve, $verdict->verdict);
        self::assertPlatformCallCount(2);
        self::assertToolCallCount(35);
    }

    public function testAFinalAnswerThatIsNotAVerdictEndsAsChanges(): void
    {
        $verdict = $this->review('Add a cart.', [new ToolCall('1', 'read_file', ['path' => 'src/Cart.php'])], 'Looks good to me.');

        self::assertSame(Decision::Changes, $verdict->verdict);
        self::assertSame(Verdict::INVALID_OUTPUT, $verdict->findings[0]->id);
    }

    public function testASampleChangeWithAWhatCommentAndASingleImplementationInterfaceGetsFindingsThatCiteThoseRules(): void
    {
        $this->fixture->write('src/CartRepository.php', "<?php\n\ninterface CartRepository\n{\n    public function find(int \$id): Cart;\n}\n");
        $this->fixture->write('src/DoctrineCartRepository.php', "<?php\n\nfinal class DoctrineCartRepository implements CartRepository\n{\n    public function find(int \$id): Cart\n    {\n        // loop over the items and add up the price\n        return new Cart();\n    }\n}\n");
        $whatComment = VerdictDocument::fromTheDesign()->withFinding('id', 'F1')->withFinding('file', 'src/DoctrineCartRepository.php')->withFinding('line', 7)
            ->withFinding('symbol', 'DoctrineCartRepository::find')->withFinding('text', 'The comment restates what the next line does instead of giving a reason.')
            ->withFinding('requirement', 'Comments say why, never what')->withFinding('evidence', ['probe' => 'read_file src/DoctrineCartRepository.php', 'output' => '// loop over the items and add up the price']);
        $singleImplementation = VerdictDocument::fromTheDesign()->withFinding('id', 'F2')->withFinding('file', 'src/CartRepository.php')->withFinding('line', 3)
            ->withFinding('symbol', 'CartRepository')->withFinding('text', 'The interface has one implementation inside the module and is no module boundary.')
            ->withFinding('requirement', 'SOLID, pragmatically')->withFinding('evidence', ['probe' => 'read_file src/CartRepository.php', 'output' => 'interface CartRepository']);
        $answer = json_encode(['verdict' => 'changes', 'findings' => [$whatComment->finding(), $singleImplementation->finding()], 'resolved' => [], 'follow_ups' => []], JSON_THROW_ON_ERROR);
        $reads = [new ToolCall('1', 'read_file', ['path' => 'src/CartRepository.php']), new ToolCall('2', 'read_file', ['path' => 'src/DoctrineCartRepository.php'])];

        $verdict = $this->review('Load carts through a repository.', $reads, $answer);

        self::assertSame(['Comments say why, never what', 'SOLID, pragmatically'], array_map(static fn ($finding): ?string => $finding->requirement, $verdict->findings));
        self::assertSame(['DoctrineCartRepository::find', 'CartRepository'], array_map(static fn ($finding): ?string => $finding->symbol, $verdict->findings));
        self::assertToolCallCount(2);
        self::assertStringContainsString('loop over the items and add up the price', $this->sent[2]);
        self::assertStringContainsString('interface CartRepository', $this->sent[2]);
    }

    public function testRoundTwoReadsTheTokensRoundOneCached(): void
    {
        $platform = $this->reviewWithTwoRounds();

        $usages = $this->usagesOf($platform);

        self::assertSame(1_200, $usages[0]->getCacheCreationTokens());
        self::assertNull($usages[0]->getCacheReadTokens());
        self::assertSame(1_200, $usages[1]->getCacheReadTokens());
    }

    public function testEveryRoundMarksSystemToolsAndTheLatestMessageAsCacheable(): void
    {
        $this->reviewWithTwoRounds();

        self::assertCount(2, $this->requests);
        foreach ($this->requests as $body) {
            /** @var array{system: non-empty-list<array{cache_control?: array<string, string>}>, tools: non-empty-list<array{cache_control?: array<string, string>}>, messages: non-empty-list<array{content: non-empty-list<array{cache_control?: array<string, string>}>}>} $request */
            $request = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['type' => 'ephemeral'], array_last($request['system'])['cache_control'] ?? null);
            self::assertSame(['type' => 'ephemeral'], array_last($request['tools'])['cache_control'] ?? null);
            self::assertSame(['type' => 'ephemeral'], array_last(array_last($request['messages'])['content'])['cache_control'] ?? null);
        }
    }

    protected static function getAiDataCollector(): DataCollector
    {
        $collector = self::getContainer()->get(self::COLLECTOR);
        self::assertInstanceOf(DataCollector::class, $collector);

        return $collector;
    }

    /**
     * @return list<string>
     */
    private function rolesIn(MessageBag $conversation): array
    {
        return array_map(static fn (MessageInterface $message): string => $message->getRole()->value, $conversation->getMessages());
    }

    // Runs the reviewer over the Anthropic bridge with a scripted HTTP client, so the request the bridge builds and the usage it reads back are the real ones.
    private function reviewWithTwoRounds(): TraceablePlatform
    {
        $responses = [
            $this->anthropic([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'read_file', 'input' => ['path' => 'src/Cart.php']]], ['cache_creation_input_tokens' => 1_200, 'input_tokens' => 40]),
            $this->anthropic([['type' => 'text', 'text' => VerdictDocument::fromTheDesign()->json()]], ['cache_read_input_tokens' => 1_200, 'input_tokens' => 80]),
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): JsonMockResponse {
            self::assertIsString($options['body']);
            $this->requests[] = $options['body'];

            return array_shift($responses) ?? self::fail('The reviewer asked for a third round.');
        });
        $platform = new TraceablePlatform(Factory::createPlatform('placeholder', $http, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher));
        $reviewer = new ReviewerAgent(new ReviewerPrompt(new Fence(), new VerdictParser()), new ReviewerToolboxFactory()->create(...), new InMemoryMessageStore());

        $reviewer->review(new ReviewRequest($this->fixture->checkout, new ReviewBrief('Add a cart.', []), new ReviewerConversation('You review for correctness.', new UuidV7())), $platform, 'claude-sonnet-5-5');

        return $platform;
    }

    /**
     * @param list<array<string, mixed>> $content
     * @param array<string, int>         $usage
     */
    private function anthropic(array $content, array $usage): JsonMockResponse
    {
        return new JsonMockResponse([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => $content,
            'stop_reason' => 'tool_use' === $content[0]['type'] ? 'tool_use' : 'end_turn',
            'usage' => $usage + ['output_tokens' => 10],
        ]);
    }

    /**
     * @return list<TokenUsageInterface>
     */
    private function usagesOf(TraceablePlatform $platform): array
    {
        return array_values(array_map(static function (array $call): TokenUsageInterface {
            $result = $call['result'];
            self::assertInstanceOf(DeferredResult::class, $result);
            $usage = $result->getResult()->getMetadata()->get('token_usage');
            self::assertInstanceOf(TokenUsageInterface::class, $usage);

            return $usage;
        }, $platform->getCalls()));
    }
}
