<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Review\Contract\ChangeUnderReview;
use Intakt\Core\Review\Contract\Checkout;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Contract\ReviewRequest;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Infrastructure\ReviewerMcpToolboxFactory;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewServerHttpClient;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Kernel;
use Intakt\Tests\Core\Mcp\Contract\KernelHttpClient;
use Intakt\Tests\Core\Review\Infrastructure\RecordingHttpClient;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use NoDiscard;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\MessageStore\ManagedMessageStoreInterface;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolFactory\MemoryToolFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServicesResetterInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\UuidV7;

// Covers nothing because the subject is the wiring: the Symfony AI services of one kernel that is never rebooted, as FrankenPHP's worker mode runs it.
#[Group('persistence')]
#[CoversNothing]
final class SymfonyAiResetTest extends TestCase
{
    private const array FRESH_ROUND = ['server/discover', 'tools/list'];

    // Listed one by one, because state a request leaves after the kernel's reset is a leak until someone judges it harmless or reproduces it upstream.
    private const array LEFT_BEHIND = [
        // Harmless: the client counts on across connections, and a JSON-RPC id only has to be unique, so a number is all a later request sees.
        'Mcp\\Client\\State\\ClientState::$requestIdCounter',
        // A leak, reproduced in upstream/ai-platform-provider-keeps-resolved-model/; not reachable in Intakt, which always routes through supports().
        'Symfony\\AI\\Platform\\Provider::$resolvedModel',
    ];

    /** @var list<KernelInterface> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
    }

    public function testASecondReviewerRunSeesOnlyItsOwnModelToolsAndConversation(): void
    {
        $kernel = $this->bootedKernel(new AiPlatformKernel('test', true));
        $this->emptyDatabase($kernel);

        $this->review($kernel, $this->reviewer($kernel, $this->toolNamed('first_tool'), 'model-a?temperature=0.2'), 'Review the first change.');
        $this->nextRequest($kernel);
        $this->review($kernel, $this->reviewer($kernel, $this->toolNamed('second_tool'), 'model-b'), 'Review the second change.');
        [$first, $second] = $this->providerRequests($kernel);

        self::assertSame(['model' => 'model-a', 'temperature' => 0.2, 'tools' => ['first_tool'], 'messages' => ['Review the first change.']], $this->seenBy($first));
        self::assertSame(['model' => 'model-b', 'temperature' => null, 'tools' => ['second_tool'], 'messages' => ['Review the second change.']], $this->seenBy($second));
    }

    public function testEachReviewRoundConnectsToTheReviewServerAndListsItsToolsAfresh(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $rounds = new RoundFixture($this->container($kernel));
        $server = $this->reviewServerFor($kernel);

        $first = $this->callsOfRound($kernel, $rounds->start(1), $server);
        $this->nextRequest($kernel);
        $second = $this->callsOfRound($kernel, $rounds->start(1), $server);

        self::assertSame(self::FRESH_ROUND, $first);
        self::assertSame(self::FRESH_ROUND, $second);
    }

    public function testWithoutTheMcpConnectionResetTheNextRoundReusesTheConnectionOfTheRoundBefore(): void
    {
        $kernel = $this->bootedKernel(new WorkerKernel('test', true, 'mcp.client.reviewer.server.review'));
        $rounds = new RoundFixture($this->container($kernel));
        $server = $this->reviewServerFor($kernel);

        $first = $this->callsOfRound($kernel, $rounds->start(1), $server);
        $this->nextRequest($kernel);
        $second = $this->callsOfRound($kernel, $rounds->start(1), $server);

        self::assertSame(self::FRESH_ROUND, $first);
        self::assertSame(['tools/list'], $second);
    }

    public function testWithoutTheMcpToolboxResetTheNextRoundGetsTheToolListOfTheRoundBefore(): void
    {
        $kernel = $this->bootedKernel(new WorkerKernel('test', true, 'ai.toolbox.reviewer.mcp.reviewer.review'));
        $rounds = new RoundFixture($this->container($kernel));
        $server = $this->reviewServerFor($kernel);

        $first = $this->callsOfRound($kernel, $rounds->start(1), $server);
        $this->nextRequest($kernel);
        $second = $this->callsOfRound($kernel, $rounds->start(1), $server);

        self::assertSame(self::FRESH_ROUND, $first);
        self::assertSame([], $second);
    }

    public function testTheSymfonyAiStateARequestLeavesBehindIsOnlyTheJudgedList(): void
    {
        $kernel = $this->bootedKernel(new AiPlatformKernel('test', true));
        $rounds = new RoundFixture($this->container($kernel));
        $this->setUpConversations($kernel);
        // Not recorded, because the recording would be state the walk reports.
        $this->serveReviewToolsFor($kernel, new KernelHttpClient($this->bootedKernel(new Kernel('test', false))));

        $this->reviewRound($kernel, $rounds->start(1), 'model-a?temperature=0.2');
        $this->nextRequest($kernel);
        $before = AiServiceState::of($kernel);
        $this->reviewRound($kernel, $rounds->start(1), 'model-b');
        $this->nextRequest($kernel);

        self::assertSame(self::LEFT_BEHIND, AiServiceState::of($kernel)->changedSince($before));
    }

    private function review(KernelInterface $kernel, Agent $reviewer, string $task): void
    {
        $conversation = $this->runsOf($kernel)->started(MessageBag::forId(new UuidV7(), Message::ofUser($task)));
        $reviewer->call($conversation)->asText();
    }

    private function reviewer(KernelInterface $kernel, ToolboxInterface $tools, string $model): Agent
    {
        $platform = $this->container($kernel)->get(AiPlatformKernel::PLATFORM);
        assert($platform instanceof PlatformInterface && '' !== $model);

        return $this->runsOf($kernel)->agent($tools, $platform, $model);
    }

    private function runsOf(KernelInterface $kernel): ReviewerRuns
    {
        $runs = $this->container($kernel)->get(ReviewerRuns::class);
        assert($runs instanceof ReviewerRuns);

        return $runs;
    }

    private function reviewRound(KernelInterface $kernel, ReviewRound $round, string $model): void
    {
        $tools = $this->toolboxesOf($kernel)->create($this->requestFor($round));
        $this->review($kernel, $this->reviewer($kernel, $tools, $model), 'Review round '.$round->id()->toRfc4122().'.');
    }

    /**
     * @return list<string>
     */
    #[NoDiscard]
    private function callsOfRound(KernelInterface $kernel, ReviewRound $round, RecordingHttpClient $server): array
    {
        $sentBefore = count($server->sent);

        self::assertNotSame([], $this->toolboxesOf($kernel)->create($this->requestFor($round))->getTools());

        return array_map(static function (RequestInterface $request): string {
            $call = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            assert(is_array($call) && is_string($call['method']));

            return $call['method'];
        }, array_slice($server->sent, $sentBefore));
    }

    // The kernel runs its services_resetter at the start of the next handle() after a terminate; calling it here is what a new request starts with, because reviewer work is not a request.
    private function nextRequest(KernelInterface $kernel): void
    {
        $resetter = $kernel->getContainer()->get('services_resetter');
        assert($resetter instanceof ServicesResetterInterface);
        $resetter->reset();
    }

    private function toolNamed(string $name): ToolboxInterface
    {
        $tool = new NamedTool();

        return new Toolbox([$tool], new MemoryToolFactory()->addTool($tool, $name, 'A tool of one run.'));
    }

    /**
     * @param array<mixed> $request
     *
     * @return array{model: mixed, temperature: mixed, tools: list<mixed>, messages: list<mixed>}
     */
    private function seenBy(array $request): array
    {
        $tools = is_array($request['tools'] ?? null) ? $request['tools'] : [];
        $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];

        return [
            'model' => $request['model'] ?? null,
            'temperature' => $request['temperature'] ?? null,
            'tools' => array_column(array_column($tools, 'function'), 'name'),
            'messages' => array_column(array_filter($messages, static fn (mixed $message): bool => is_array($message) && 'user' === ($message['role'] ?? null)), 'content'),
        ];
    }

    /**
     * @return list<array<mixed>>
     */
    private function providerRequests(KernelInterface $kernel): array
    {
        $answers = $this->container($kernel)->get(ProviderAnswers::class);
        assert($answers instanceof ProviderAnswers);

        return $answers->requests;
    }

    private function requestFor(ReviewRound $round): ReviewRequest
    {
        return new ReviewRequest(new Checkout($round->checkoutRoot()), new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), []), new ReviewerConversation('You review for correctness.', new UuidV7(), $round->id()));
    }

    private function toolboxesOf(KernelInterface $kernel): ReviewerMcpToolboxFactory
    {
        $factory = $this->container($kernel)->get(ReviewerMcpToolboxFactory::class);
        assert($factory instanceof ReviewerMcpToolboxFactory);

        return $factory;
    }

    // A kernel of its own, because the web server runs in a process of its own.
    private function reviewServerFor(KernelInterface $kernel): RecordingHttpClient
    {
        $recording = new RecordingHttpClient(new KernelHttpClient($this->bootedKernel(new Kernel('test', false))));
        $this->serveReviewToolsFor($kernel, $recording);

        return $recording;
    }

    // Set before the reviewer's MCP connection is built, because the connection keeps the client it was built with.
    private function serveReviewToolsFor(KernelInterface $kernel, ClientInterface $http): void
    {
        $credential = $this->container($kernel)->get(ReviewCredential::class);
        assert($credential instanceof ReviewCredential);
        $this->container($kernel)->set(ReviewServerHttpClient::class, new ReviewServerHttpClient($credential, $http));
    }

    private function emptyDatabase(KernelInterface $kernel): void
    {
        EmptyDatabase::in($this->container($kernel));
        $this->setUpConversations($kernel);
    }

    private function setUpConversations(KernelInterface $kernel): void
    {
        $conversations = $this->container($kernel)->get(MessageStoreInterface::class);
        assert($conversations instanceof ManagedMessageStoreInterface);
        $conversations->setup();
    }

    private function bootedKernel(KernelInterface $kernel): KernelInterface
    {
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    private function container(KernelInterface $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }
}
