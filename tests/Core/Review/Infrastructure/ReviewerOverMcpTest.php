<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Closure;
use GuzzleHttp\Psr7\Response;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\FencedToolbox;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerMcpToolboxFactory;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\ReviewServerHttpClient;
use Intakt\Core\Review\Infrastructure\ReviewServerLost;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\Infrastructure\ToolsOfRound;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewedRepository;
use Intakt\Kernel;
use Intakt\Tests\Core\Mcp\Contract\KernelHttpClient;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Uid\UuidV7;

#[Group('persistence')]
#[CoversClass(ReviewerMcpToolboxFactory::class)]
#[CoversClass(ReviewServerHttpClient::class)]
#[CoversClass(ToolsOfRound::class)]
#[UsesClass(ReviewerAgent::class)]
#[UsesClass(ReviewServerLost::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VerdictUnreadable::class)]
#[UsesClass(ReviewerToolboxFactory::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(ReviewerRuns::class)]
#[UsesClass(MalformedToolCalls::class)]
#[UsesClass(FencedToolbox::class)]
#[UsesClass(ReviewCredential::class)]
#[UsesClass(ReviewedRepository::class)]
#[UsesClass(Checkout::class)]
final class ReviewerOverMcpTest extends KernelTestCase
{
    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private ReviewRound $round;

    private ReviewCredential $credential;

    private RecordingHttpClient $http;

    private ?Kernel $server = null;

    public function testAReviewWithToolsOverMcpReachesTheSameVerdictAsWithTheToolsInProcess(): void
    {
        $inProcess = $this->review(new ReviewerToolboxFactory()->create(...), 'read_file');
        $overMcp = $this->review($this->mcpToolboxes()->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertEquals($inProcess, $overMcp);
        self::assertSame(Verdict::class, $overMcp::class);
    }

    public function testTheFileTheModelReadsOverMcpIsTheOneAtTheHeadOfTheRound(): void
    {
        $toolbox = $this->mcpToolboxes()->create($this->request());

        $result = $toolbox->execute(new ToolCall('1', ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file', ['path' => 'RoundFixture.php']))->getResult();

        self::assertIsString($result);
        self::assertStringContainsString('final readonly class RoundFixture', $result);
    }

    public function testAMalformedToolCallOverMcpStillRefusesTheAnswerAsUnreadable(): void
    {
        $this->expectException(VerdictUnreadable::class);
        $this->expectExceptionMessageMatches('/malformed tool call/');

        $this->review($this->mcpToolboxes()->create(...), 'no_such_tool');
    }

    public function testAToolCallOverMcpWithoutItsRequiredArgumentIsAMalformedCallAsInProcess(): void
    {
        $inProcess = $this->refusalOf(new ReviewerToolboxFactory()->create(...), 'read_file');
        $overMcp = $this->refusalOf($this->mcpToolboxes()->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertStringContainsString('malformed tool call', $inProcess);
        self::assertSame($inProcess, $overMcp);
    }

    public function testEveryMcpRequestCarriesTheCredentialOfTheRoundUnderReview(): void
    {
        $this->review($this->mcpToolboxes()->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertNotSame([], $this->http->sent);

        foreach ($this->http->sent as $request) {
            self::assertSame('Bearer '.$this->credential->issueFor($this->round->id()->toRfc4122()), $request->getHeaderLine('Authorization'));
        }
    }

    public function testAReviewWithoutARoundIsRefusedBecauseNoCredentialCouldNameItsHead(): void
    {
        $request = new ReviewRequest(new Checkout($this->round->checkoutRoot()), new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), [], 1), new ReviewerConversation('You review for correctness.', new UuidV7()));

        $this->expectException(LogicException::class);

        $this->mcpToolboxes()->create($request);
    }

    public function testAReviewWhoseServerCannotBeReachedIsBlockedInsteadOfJudgedWithoutTools(): void
    {
        $refused = self::createStub(ClientInterface::class);
        $refused->method('sendRequest')->willThrowException(new RuntimeException('Connection refused.'));

        $verdict = $this->review($this->mcpToolboxes($refused)->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::NO_TOOLS, $verdict->findings[0]->id);
        self::assertLoggedErrors(8, 'Transport error');
        self::assertLoggedError('error', 'Failed to list the tools of MCP server "review", it contributes none for the next 60 seconds.');
    }

    public function testAReviewThatLosesItsServerAfterListingTheToolsIsBlockedInsteadOfApproved(): void
    {
        $http = new SeverableHttpClient(new KernelHttpClient($this->server()));
        $factory = $this->mcpToolboxes($http);
        self::assertNotSame([], $factory->create($this->request())->getTools());
        $http->sever();

        $verdict = $this->review($factory->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::NO_TOOLS, $verdict->findings[0]->id);
        self::assertLoggedErrors(2, 'Transport error');
    }

    #[DataProvider('failedAnswers')]
    public function testAReviewWhoseServerAnswersWithAFailureAfterListingTheToolsIsBlockedInsteadOfApproved(int $status, string $contentType, string $body): void
    {
        $http = new SeverableHttpClient(new KernelHttpClient($this->server()));
        $factory = $this->mcpToolboxes($http);
        self::assertNotSame([], $factory->create($this->request())->getTools());
        $http->sever(new Response($status, ['Content-Type' => $contentType], $body));

        $verdict = $this->review($factory->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::NO_TOOLS, $verdict->findings[0]->id);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function failedAnswers(): iterable
    {
        yield 'unauthorized' => [401, 'application/json', 'Unauthorized'];
        yield 'server error' => [500, 'text/html', 'Internal Server Error'];
        yield 'malformed json' => [200, 'application/json', '{"jsonrpc":'];
        yield 'internal error of the protocol' => [500, 'application/json', '{"jsonrpc":"2.0","id":2,"error":{"code":-32603,"message":"Error while executing tool"}}'];
    }

    public function testTheNextReviewAfterAFailedToolCallAsksTheServerAgainWithoutWaiting(): void
    {
        $http = new SeverableHttpClient(new KernelHttpClient($this->server()));
        $factory = $this->mcpToolboxes($http);
        self::assertNotSame([], $factory->create($this->request())->getTools());
        $http->sever(new Response(500, ['Content-Type' => 'text/html'], 'Internal Server Error'));
        $failed = $this->review($factory->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');
        $http->restore();
        $sentBefore = count($this->http->sent);

        $verdict = $this->review($factory->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file');

        self::assertSame(Decision::Blocked, $failed->verdict);
        self::assertSame(Decision::Approve, $verdict->verdict);
        self::assertGreaterThan($sentBefore, count($this->http->sent));
    }

    public function testAToolErrorTheServerAnswersStaysWithTheModel(): void
    {
        $verdict = $this->review($this->mcpToolboxes()->create(...), ReviewerMcpToolboxFactory::TOOL_PREFIX.'read_file', ['path' => 'no-such-file.php']);

        self::assertSame(Decision::Approve, $verdict->verdict);
    }

    public function testNoRequestReachesTheReviewServerOutsideAReview(): void
    {
        $this->mcpToolboxes();
        $client = self::getContainer()->get(ReviewServerHttpClient::class);
        self::assertInstanceOf(ReviewServerHttpClient::class, $client);

        $this->expectException(LogicException::class);

        $client->sendRequest(new Psr18Client()->createRequest('POST', 'http://localhost/mcp/review'));
    }

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $this->round = new RoundFixture(self::getContainer())->start(1);
        $credential = self::getContainer()->get(ReviewCredential::class);
        self::assertInstanceOf(ReviewCredential::class, $credential);
        $this->credential = $credential;
    }

    protected function tearDown(): void
    {
        $this->server?->shutdown();
        parent::tearDown();
    }

    // A kernel of its own, as the web server is a process of its own: the kernel under test resets its services before each request it handles, which would end the reviewer's call mid-way.
    private function server(): Kernel
    {
        $this->server = new Kernel('test', false);
        $this->server->boot();

        return $this->server;
    }

    /**
     * @param array<string, string>                    $arguments
     * @param Closure(ReviewRequest): ToolboxInterface $toolboxes
     */
    private function review(Closure $toolboxes, string $tool, array $arguments = ['path' => 'RoundFixture.php']): Verdict
    {
        $step = 0;
        $script = static function () use (&$step, $tool, $arguments) {
            return 0 === $step++ ? new ToolCallResult([new ToolCall('1', $tool, $arguments)]) : self::APPROVE;
        };
        $platform = MockPlatformFactory::createPlatform($script, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $reviewer = new ReviewerAgent(RemembersNothing::prompt(), $toolboxes, new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests(new InMemoryLedger(), new LedgerPayloads(StoredSerializerFactory::create())))));

        return $reviewer->review($this->request(), $platform, new ResolvedModel(Provider::Anthropic, 'reviewer-model'));
    }

    // Through the container, so the configured mcp_server entry and its connection are what the test exercises.
    private function mcpToolboxes(?ClientInterface $http = null): ReviewerMcpToolboxFactory
    {
        $this->http = new RecordingHttpClient($http ?? new KernelHttpClient($this->server()));
        self::getContainer()->set(ReviewServerHttpClient::class, new ReviewServerHttpClient($this->credential, $this->http));
        $factory = self::getContainer()->get(ReviewerMcpToolboxFactory::class);
        self::assertInstanceOf(ReviewerMcpToolboxFactory::class, $factory);

        return $factory;
    }

    /**
     * @param Closure(ReviewRequest): ToolboxInterface $toolboxes
     */
    private function refusalOf(Closure $toolboxes, string $tool): string
    {
        try {
            $this->review($toolboxes, $tool, []);
        } catch (VerdictUnreadable $refused) {
            return $refused->getMessage();
        }

        self::fail('The answer after a call without its argument was not refused.');
    }

    private function request(): ReviewRequest
    {
        return new ReviewRequest(new Checkout($this->round->checkoutRoot()), new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), [], 1), new ReviewerConversation('You review for correctness.', new UuidV7(), $this->round->id()));
    }
}
