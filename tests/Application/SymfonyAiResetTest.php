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
use Intakt\Tests\Support\RebuiltContainer;
use NoDiscard;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolFactory\MemoryToolFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
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

    private const string SET_UP = 'Set while the service is built or on its first use, from configuration, and the same for every request.';

    private const string CACHE = 'A cache of what the configuration gives, filled on first use with the same content for every request.';

    private const string MCP_CONNECTION = 'Written while a round talks to the review server; ServerConnection::reset() disconnects at the next request, and the next connect writes it afresh.';

    private const string PER_CALL = 'Cleared by the library when the call or the MCP request that wrote it ends or the next one starts.';

    private const string REFRESHED = 'Written afresh by every getTools(), which an agent runs before it calls a tool.';

    private const string COUNTER = 'Harmless: a counter whose numbers only have to be unique, so a number is all a later request sees.';

    private const string LEAK = 'A leak, reproduced in upstream/ai-platform-provider-keeps-resolved-model/; not reachable in Intakt, which always routes through supports().';

    // Judged one by one, because the run-twice test above cannot see state on paths it does not take (structured output), and a new entry is a leak until someone judges it.
    private const array MUTABLE_WITHOUT_RESET = [
        'Mcp\\Capability\\Discovery\\SchemaValidator::$decodedSchemas' => self::CACHE,
        'Mcp\\Capability\\Discovery\\SchemaValidator::$jsonSchemaValidator' => self::SET_UP,
        'Mcp\\Capability\\Discovery\\SchemaValidator::$logger' => self::SET_UP,
        'Mcp\\Capability\\Registry::$loaded' => self::CACHE,
        'Mcp\\Capability\\Registry::$loading' => self::CACHE,
        'Mcp\\Capability\\Registry::$prompts' => self::CACHE,
        'Mcp\\Capability\\Registry::$resourceTemplates' => self::CACHE,
        'Mcp\\Capability\\Registry::$resources' => self::CACHE,
        'Mcp\\Capability\\Registry::$tools' => self::CACHE,
        'Mcp\\Capability\\Registry\\ResourceTemplateReference::$uriTemplateRegex' => self::CACHE,
        'Mcp\\Capability\\Registry\\ResourceTemplateReference::$variableNames' => self::CACHE,
        'Mcp\\Client::$transport' => self::MCP_CONNECTION,
        'Mcp\\Client\\Builder::$capabilities' => self::SET_UP,
        'Mcp\\Client\\Builder::$description' => self::SET_UP,
        'Mcp\\Client\\Builder::$extensions' => self::SET_UP,
        'Mcp\\Client\\Builder::$fallbackProtocolVersion' => self::SET_UP,
        'Mcp\\Client\\Builder::$initTimeout' => self::SET_UP,
        'Mcp\\Client\\Builder::$logger' => self::SET_UP,
        'Mcp\\Client\\Builder::$maxRetries' => self::SET_UP,
        'Mcp\\Client\\Builder::$name' => self::SET_UP,
        'Mcp\\Client\\Builder::$notificationHandlers' => self::SET_UP,
        'Mcp\\Client\\Builder::$protocolVersion' => self::SET_UP,
        'Mcp\\Client\\Builder::$requestHandlers' => self::SET_UP,
        'Mcp\\Client\\Builder::$requestTimeout' => self::SET_UP,
        'Mcp\\Client\\Builder::$title' => self::SET_UP,
        'Mcp\\Client\\Builder::$version' => self::SET_UP,
        'Mcp\\Client\\Protocol::$envelope' => self::MCP_CONNECTION,
        'Mcp\\Client\\Protocol::$headers' => self::MCP_CONNECTION,
        'Mcp\\Client\\Protocol::$logLevel' => self::MCP_CONNECTION,
        'Mcp\\Client\\Protocol::$logger' => self::SET_UP,
        'Mcp\\Client\\Protocol::$messageFactory' => self::SET_UP,
        'Mcp\\Client\\Protocol::$notificationHandlers' => self::SET_UP,
        'Mcp\\Client\\Protocol::$onProgress' => self::MCP_CONNECTION,
        'Mcp\\Client\\Protocol::$progressToken' => self::MCP_CONNECTION,
        'Mcp\\Client\\Protocol::$progressTokens' => self::COUNTER,
        'Mcp\\Client\\Protocol::$state' => self::MCP_CONNECTION,
        'Mcp\\Client\\Protocol::$tools' => self::MCP_CONNECTION,
        'Mcp\\Client\\Protocol::$transport' => self::MCP_CONNECTION,
        'Mcp\\Client\\State\\ClientState::$initialized' => self::MCP_CONNECTION,
        'Mcp\\Client\\State\\ClientState::$instructions' => self::MCP_CONNECTION,
        'Mcp\\Client\\State\\ClientState::$pendingRequests' => self::MCP_CONNECTION,
        'Mcp\\Client\\State\\ClientState::$progressUpdates' => self::MCP_CONNECTION,
        'Mcp\\Client\\State\\ClientState::$protocolVersion' => self::MCP_CONNECTION,
        'Mcp\\Client\\State\\ClientState::$requestIdCounter' => self::COUNTER,
        'Mcp\\Client\\State\\ClientState::$responses' => self::MCP_CONNECTION,
        'Mcp\\Client\\State\\ClientState::$serverCapabilities' => self::MCP_CONNECTION,
        'Mcp\\Client\\State\\ClientState::$serverInfo' => self::MCP_CONNECTION,
        'Mcp\\Client\\Stateless\\ToolCatalog::$rejected' => self::MCP_CONNECTION,
        'Mcp\\Client\\Stateless\\ToolCatalog::$schemas' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\BaseTransport::$closeCallback' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\BaseTransport::$errorCallback' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\BaseTransport::$initializeCallback' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\BaseTransport::$logger' => self::SET_UP,
        'Mcp\\Client\\Transport\\BaseTransport::$messageCallback' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\BaseTransport::$state' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$activeFiber' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$activeStream' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$activeSuspend' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$headerCallback' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$httpClient' => self::SET_UP,
        'Mcp\\Client\\Transport\\HttpTransport::$lastEventId' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$listenStream' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$nextReconnectAtMs' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$reconnectAttempts' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$requestFactory' => self::SET_UP,
        'Mcp\\Client\\Transport\\HttpTransport::$serverRetryMs' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$sessionId' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$sseRequestCompleted' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$sseRequestId' => self::MCP_CONNECTION,
        'Mcp\\Client\\Transport\\HttpTransport::$streamFactory' => self::SET_UP,
        'Mcp\\Client\\Transport\\SseParser::$buffer' => self::MCP_CONNECTION,
        'Mcp\\Server\\Builder::$cachePolicy' => self::SET_UP,
        'Mcp\\Server\\Builder::$container' => self::SET_UP,
        'Mcp\\Server\\Builder::$discoverer' => self::SET_UP,
        'Mcp\\Server\\Builder::$discoveryBasePath' => self::SET_UP,
        'Mcp\\Server\\Builder::$discoveryCache' => self::SET_UP,
        'Mcp\\Server\\Builder::$discoveryExcludeDirs' => self::SET_UP,
        'Mcp\\Server\\Builder::$discoveryNamePatterns' => self::SET_UP,
        'Mcp\\Server\\Builder::$discoveryScanDirs' => self::SET_UP,
        'Mcp\\Server\\Builder::$eventDispatcher' => self::SET_UP,
        'Mcp\\Server\\Builder::$explicitPrompts' => self::SET_UP,
        'Mcp\\Server\\Builder::$explicitResourceTemplates' => self::SET_UP,
        'Mcp\\Server\\Builder::$explicitResources' => self::SET_UP,
        'Mcp\\Server\\Builder::$explicitTools' => self::SET_UP,
        'Mcp\\Server\\Builder::$extensionMessages' => self::SET_UP,
        'Mcp\\Server\\Builder::$extensionMethods' => self::SET_UP,
        'Mcp\\Server\\Builder::$extensions' => self::SET_UP,
        'Mcp\\Server\\Builder::$gcDivisor' => self::SET_UP,
        'Mcp\\Server\\Builder::$gcProbability' => self::SET_UP,
        'Mcp\\Server\\Builder::$hasCustomRegistry' => self::SET_UP,
        'Mcp\\Server\\Builder::$headerValidation' => self::SET_UP,
        'Mcp\\Server\\Builder::$inputRequiredRounds' => self::SET_UP,
        'Mcp\\Server\\Builder::$inputRequiredShim' => self::SET_UP,
        'Mcp\\Server\\Builder::$inputRequiredTimeout' => self::SET_UP,
        'Mcp\\Server\\Builder::$instructions' => self::SET_UP,
        'Mcp\\Server\\Builder::$lazyLoading' => self::SET_UP,
        'Mcp\\Server\\Builder::$loaders' => self::SET_UP,
        'Mcp\\Server\\Builder::$logger' => self::SET_UP,
        'Mcp\\Server\\Builder::$modernVersions' => self::SET_UP,
        'Mcp\\Server\\Builder::$notificationBus' => self::SET_UP,
        'Mcp\\Server\\Builder::$notificationHandlers' => self::SET_UP,
        'Mcp\\Server\\Builder::$paginationLimit' => self::SET_UP,
        'Mcp\\Server\\Builder::$parts' => self::SET_UP,
        'Mcp\\Server\\Builder::$prompts' => self::SET_UP,
        'Mcp\\Server\\Builder::$protocolVersion' => self::SET_UP,
        'Mcp\\Server\\Builder::$referenceHandler' => self::SET_UP,
        'Mcp\\Server\\Builder::$registry' => self::SET_UP,
        'Mcp\\Server\\Builder::$requestHandlers' => self::SET_UP,
        'Mcp\\Server\\Builder::$requestStateKey' => self::SET_UP,
        'Mcp\\Server\\Builder::$requestStateTtl' => self::SET_UP,
        'Mcp\\Server\\Builder::$resourceTemplates' => self::SET_UP,
        'Mcp\\Server\\Builder::$resources' => self::SET_UP,
        'Mcp\\Server\\Builder::$schemaGenerator' => self::SET_UP,
        'Mcp\\Server\\Builder::$schemaValidator' => self::SET_UP,
        'Mcp\\Server\\Builder::$serverCapabilities' => self::SET_UP,
        'Mcp\\Server\\Builder::$serverInfo' => self::SET_UP,
        'Mcp\\Server\\Builder::$sessionLock' => self::SET_UP,
        'Mcp\\Server\\Builder::$sessionManager' => self::SET_UP,
        'Mcp\\Server\\Builder::$sessionStore' => self::SET_UP,
        'Mcp\\Server\\Builder::$subscriptionLifetime' => self::SET_UP,
        'Mcp\\Server\\Builder::$subscriptionManager' => self::SET_UP,
        'Mcp\\Server\\Builder::$tools' => self::SET_UP,
        'Mcp\\Server\\Handler\\Request\\CallToolHandler::$schemaValidator' => self::SET_UP,
        'Mcp\\Server\\Protocol::$awaitedRequestIds' => self::PER_CALL,
        'Mcp\\Server\\Protocol::$droppedAwaitedRequestIds' => self::PER_CALL,
        'Mcp\\Server\\Protocol::$fiberOutgoingMessages' => self::PER_CALL,
        'Mcp\\Server\\Session\\FileSessionLock::$handles' => self::PER_CALL,
        'Symfony\\AI\\Agent\\Toolbox\\ChainToolbox::$owners' => self::REFRESHED,
        'Symfony\\AI\\Agent\\Toolbox\\ToolFactory\\MemoryToolFactory::$tools' => self::CACHE,
        'Symfony\\AI\\Agent\\Toolbox\\Toolbox::$instanceMap' => self::CACHE,
        'Symfony\\AI\\Agent\\Toolbox\\Toolbox::$toolsMetadata' => self::CACHE,
        'Symfony\\AI\\AiBundle\\Security\\EventListener\\IsGrantedToolAttributeListener::$expressionLanguage' => self::SET_UP,
        'Symfony\\AI\\McpBundle\\Routing\\RouteLoader::$loaded' => self::SET_UP,
        'Symfony\\AI\\McpBundle\\Routing\\RouteLoader::$servers' => self::SET_UP,
        'Symfony\\AI\\Platform\\Contract\\JsonSchema\\Describer\\SerializerDescriber::$describer' => self::SET_UP,
        'Symfony\\AI\\Platform\\Contract\\JsonSchema\\Describer\\TypeInfoDescriber::$objectDescriber' => self::SET_UP,
        'Symfony\\AI\\Platform\\Contract\\JsonSchema\\Describer\\TypeInfoDescriber::$typeResolver' => self::SET_UP,
        'Symfony\\AI\\Platform\\ModelCatalog\\AbstractModelCatalog::$models' => self::CACHE,
        'Symfony\\AI\\Platform\\Platform::$modelCatalog' => self::SET_UP,
        'Symfony\\AI\\Platform\\Provider::$contract' => self::SET_UP,
        'Symfony\\AI\\Platform\\Provider::$resolvedModel' => self::LEAK,
        'Symfony\\AI\\Platform\\Serializer\\MetadataNormalizer::$denormalizer' => self::SET_UP,
        'Symfony\\AI\\Platform\\Serializer\\MetadataNormalizer::$normalizer' => self::SET_UP,
        'Symfony\\AI\\Platform\\StructuredOutput\\PlatformSubscriber::$objectToPopulate' => self::PER_CALL,
        'Symfony\\AI\\Platform\\StructuredOutput\\PlatformSubscriber::$outputType' => self::PER_CALL,
        'Symfony\\AI\\Platform\\StructuredOutput\\PlatformSubscriber::$serializer' => self::SET_UP,
        'Symfony\\AI\\Store\\Indexer\\DocumentIndexer::$processor' => self::SET_UP,
        'Symfony\\AI\\Store\\Indexer\\DocumentProcessor::$filters' => self::SET_UP,
        'Symfony\\AI\\Store\\Indexer\\DocumentProcessor::$logger' => self::SET_UP,
        'Symfony\\AI\\Store\\Indexer\\DocumentProcessor::$store' => self::SET_UP,
        'Symfony\\AI\\Store\\Indexer\\DocumentProcessor::$transformers' => self::SET_UP,
        'Symfony\\AI\\Store\\Indexer\\DocumentProcessor::$vectorizer' => self::SET_UP,
        'Symfony\\Component\\Config\\Loader\\Loader::$env' => self::SET_UP,
        'Symfony\\Component\\Config\\Loader\\Loader::$resolver' => self::SET_UP,
        'Symfony\\Component\\Serializer\\Serializer::$decoder' => self::SET_UP,
        'Symfony\\Component\\Serializer\\Serializer::$encoder' => self::SET_UP,
    ];

    /** @var list<KernelInterface> */
    private array $kernels = [];

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

    public function testEveryMutableSymfonyAiStateWithoutAResetIsJudged(): void
    {
        $kernel = $this->bootedKernel(new AiPlatformKernel('test', true));

        self::assertSame(array_keys(self::MUTABLE_WITHOUT_RESET), AiServiceState::of($kernel)->mutableWithoutReset());
    }

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
    }

    private function setUpConversations(KernelInterface $kernel): void
    {
        $conversations = $this->container($kernel)->get(MessageStoreInterface::class);
        $conversations->setup();
    }

    // Set before the reviewer's MCP connection is built, because the connection keeps the client it was built with.
    private function serveReviewToolsFor(KernelInterface $kernel, ClientInterface $http): void
    {
        $credential = $this->container($kernel)->get(ReviewCredential::class);
        $this->container($kernel)->set(ReviewServerHttpClient::class, new ReviewServerHttpClient($credential, $http));
    }

    private function reviewRound(KernelInterface $kernel, ReviewRound $round, string $model): void
    {
        $tools = $this->toolboxesOf($kernel)->create($this->requestFor($round));
        $this->review($kernel, $this->reviewer($kernel, $tools, $model), 'Review round '.$round->id()->toRfc4122().'.');
    }

    private function toolboxesOf(KernelInterface $kernel): ReviewerMcpToolboxFactory
    {
        return $this->container($kernel)->get(ReviewerMcpToolboxFactory::class);
    }

    private function requestFor(ReviewRound $round): ReviewRequest
    {
        return new ReviewRequest(new Checkout($round->checkoutRoot()), new ReviewBrief(new ChangeUnderReview('alpha', 'Add a cart.', 'diff --git a/a b/a'), [], 1), new ReviewerConversation('You review for correctness.', new UuidV7(), $round->id()));
    }

    private function bootedKernel(KernelInterface $kernel): KernelInterface
    {
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    private function emptyDatabase(KernelInterface $kernel): void
    {
        EmptyDatabase::in($this->container($kernel));
        $this->setUpConversations($kernel);
    }

    private function toolNamed(string $name): ToolboxInterface
    {
        $tool = new NamedTool();

        return new Toolbox([$tool], new MemoryToolFactory()->addTool($tool, $name, 'A tool of one run.'));
    }

    private function reviewer(KernelInterface $kernel, ToolboxInterface $tools, string $model): Agent
    {
        $container = new RebuiltContainer($this->container($kernel));
        $platform = $container->get(AiPlatformKernel::PLATFORM);
        assert($platform instanceof PlatformInterface && '' !== $model);

        return $this->runsOf($kernel)->agent($tools, $platform, $model);
    }

    private function review(KernelInterface $kernel, Agent $reviewer, string $task): void
    {
        $conversation = $this->runsOf($kernel)->started(MessageBag::forId(new UuidV7(), Message::ofUser($task)));
        $reviewer->call($conversation)->asText();
    }

    private function runsOf(KernelInterface $kernel): ReviewerRuns
    {
        return $this->container($kernel)->get(ReviewerRuns::class);
    }

    // The kernel runs its services_resetter at the start of the next handle() after a terminate; calling it here is what a new request starts with, because reviewer work is not a request.
    private function nextRequest(KernelInterface $kernel): void
    {
        $resetter = $kernel->getContainer()->get('services_resetter');
        $resetter->reset();
    }

    /**
     * @return list<array<mixed>>
     */
    private function providerRequests(KernelInterface $kernel): array
    {
        $container = new RebuiltContainer($this->container($kernel));
        $answers = $container->get(ProviderAnswers::class);
        assert($answers instanceof ProviderAnswers);

        return $answers->requests();
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

    private function container(KernelInterface $kernel): TestContainer
    {
        return $kernel->getContainer()->get('test.service_container');
    }

    // A kernel of its own, because the web server runs in a process of its own.
    private function reviewServerFor(KernelInterface $kernel): RecordingHttpClient
    {
        $recording = new RecordingHttpClient(new KernelHttpClient($this->bootedKernel(new Kernel('test', false))));
        $this->serveReviewToolsFor($kernel, $recording);

        return $recording;
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
}
