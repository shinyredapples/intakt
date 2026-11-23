<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Closure;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerMcpToolboxFactory;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Server\Infrastructure\ProcessSettings;
use Intakt\Kernel;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\ReplayMarkBus;
use NoDiscard;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

// Covers nothing because the subject is the wiring: one kernel that is never rebooted, as FrankenPHP's worker mode runs it, and every service that could hold a request's state.
#[Group('persistence')]
#[CoversNothing]
final class WorkerModeTest extends TestCase
{
    private const string OWNER_TOKEN = 'owner-token-of-the-worker-mode-test';

    private const string HOST = 'localhost:7780';

    private const string LEFTOVER = 'WORKER_MODE_LEFTOVER';

    private ?KernelInterface $kernel = null;

    private ProcessSettings $processBefore;

    /**
     * @return iterable<string, array{string}>
     */
    public static function runnerModes(): iterable
    {
        yield 'one kept kernel' => ['0'];
        yield 'a kernel cloned per request' => ['1'];
    }

    #[DataProvider('runnerModes')]
    public function testARequestWithoutATokenDoesNotSeeTheOwnerOfTheRequestBefore(string $resetKernel): void
    {
        $_SERVER['FRANKENPHP_RESET_KERNEL'] = $resetKernel;
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $browser = $this->browserOn($kernel);

        $browser->request('POST', '/mcp/owner', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::OWNER_TOKEN, 'HTTP_HOST' => 'localhost']);

        self::assertSame('mcp-owner', WorkerState::in($kernel)->user);
        self::assertNull($this->nextRequestSees($browser->getKernel())->user);
    }

    public function testWithTheKernelResetTheNextRequestRunsOnAContainerOfItsOwn(): void
    {
        $_SERVER['FRANKENPHP_RESET_KERNEL'] = '1';
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $browser = $this->browserOn($kernel);

        $browser->request('POST', '/mcp/owner', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::OWNER_TOKEN, 'HTTP_HOST' => 'localhost']);
        $next = $browser->getKernel();
        $next->boot();

        self::assertNotSame($kernel->getContainer(), $next->getContainer());
    }

    #[DataProvider('runnerModes')]
    public function testARequestDoesNotSeeTheProcessSettingsTheRequestBeforeSet(string $resetKernel): void
    {
        $_SERVER['FRANKENPHP_RESET_KERNEL'] = $resetKernel;
        $browser = $this->browserOn($this->bootedKernel(new Kernel('test', true)));
        // After the boot, because FrameworkBundle adds DOCTRINE_DEPRECATIONS while booting, and a worker starts from its booted kernel.
        $booted = ProcessSettings::now();

        $this->requestThatRuns($browser, function (): void {
            umask(0o077);
            $this->otherLocale();
            $_ENV[self::LEFTOVER] = 'set by the request before';
        });
        $seen = $this->nextRequestSeesProcess($browser);

        self::assertSame($this->processBefore->umask, $seen->umask);
        self::assertSame($this->processBefore->locale, $seen->locale);
        self::assertSame($booted->env, $seen->env);
    }

    // Also with a kernel cloned per request, because the clone shares the process: FRANKENPHP_RESET_KERNEL does not hide these leaks.
    #[DataProvider('runnerModes')]
    public function testWithoutTheRestoreTheNextRequestSeesTheProcessSettingsOfTheOneBefore(string $resetKernel): void
    {
        $_SERVER['FRANKENPHP_RESET_KERNEL'] = $resetKernel;
        $browser = $this->browserOn($this->bootedKernel(new UnrestoredKernel('test', true)));

        $this->requestThatRuns($browser, function (): void {
            umask(0o077);
            $this->otherLocale();
            $_ENV[self::LEFTOVER] = 'set by the request before';
        });
        $seen = $this->nextRequestSeesProcess($browser);

        self::assertSame(0o077, $seen->umask);
        self::assertNotSame($this->processBefore->locale, $seen->locale);
        self::assertSame('set by the request before', $seen->env[self::LEFTOVER] ?? null);
    }

    #[DataProvider('runnerModes')]
    public function testAReviewCredentialDoesNotCarryItsRoundToTheNextRequest(string $resetKernel): void
    {
        $_SERVER['FRANKENPHP_RESET_KERNEL'] = $resetKernel;
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $browser = $this->browserOn($kernel);
        $round = Uuid::v7()->toRfc4122();
        $credential = $this->container($kernel)->get(ReviewCredential::class);
        self::assertInstanceOf(ReviewCredential::class, $credential);

        $browser->request('POST', '/mcp/review', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$credential->issueFor($round), 'HTTP_HOST' => 'localhost']);

        self::assertSame($round, WorkerState::in($kernel)->user);
        self::assertNull($this->nextRequestSees($browser->getKernel())->user);
    }

    public function testAFlashMessageOfOneBrowserIsNotShownToAnotherBrowser(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $container = $this->container($kernel);
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $container->set(Ledger::class, $ledger);
        $container->set('command.bus', new StartedRoundBus());
        // Answered without the database, because the review page reads the ledger in memory and another test may be emptying the database.
        $container->set(ReplayMarks::class, new ReplayMarks(new ReplayMarkBus()));
        $first = $this->browserOn($kernel);
        $second = $this->browserOn($kernel);

        $this->startRoundAs($first);
        $otherPage = $second->request('GET', '/reviews/round-1');
        $ownPage = $first->request('GET', '/reviews/round-1');

        self::assertCount(0, $otherPage->filter('.toast'));
        self::assertSame('Started round 2.', trim($ownPage->filter('.toast')->text()));
    }

    #[DataProvider('runnerModes')]
    public function testAnEntityLoadedByOneRequestIsNotInTheIdentityMapOfTheNext(string $resetKernel): void
    {
        $_SERVER['FRANKENPHP_RESET_KERNEL'] = $resetKernel;
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $this->emptyDatabase($kernel);
        $this->recordAnEvent($kernel);
        $browser = $this->browserOn($kernel);
        $browser->request('GET', '/reviews');

        self::assertGreaterThan(0, WorkerState::in($kernel)->loadedEntities);
        self::assertSame(0, $this->nextRequestSees($browser->getKernel())->loadedEntities);
    }

    public function testWithoutTheDoctrineResetTheNextRequestSeesTheEntitiesOfTheOneBefore(): void
    {
        $kernel = $this->bootedKernel(new WorkerKernel('test', true, 'doctrine'));
        $this->emptyDatabase($kernel);
        $this->recordAnEvent($kernel);
        $this->browserOn($kernel)->request('GET', '/reviews');

        self::assertGreaterThan(0, $this->nextRequestSees($kernel)->loadedEntities);
    }

    public function testWithoutTheTokenStorageResetTheNextRequestSeesTheUserOfTheOneBefore(): void
    {
        $kernel = $this->bootedKernel(new WorkerKernel('test', true, 'security.token_storage'));
        $this->browserOn($kernel)->request('POST', '/mcp/owner', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::OWNER_TOKEN, 'HTTP_HOST' => 'localhost']);

        self::assertSame('mcp-owner', $this->nextRequestSees($kernel)->user);
    }

    public function testAReviewerConversationDoesNotSeeTheMessagesOfTheConversationBefore(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $this->emptyDatabase($kernel);
        $first = new UuidV7();
        $second = new UuidV7();

        $this->browserOn($kernel)->request('POST', '/mcp/owner', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::OWNER_TOKEN, 'HTTP_HOST' => 'localhost']);
        $this->runReviewer($kernel, $first, 'Review the first change.');
        $reset = $this->nextRequestSees($kernel);
        $this->runReviewer($kernel, $second, 'Review the second change.');

        self::assertNull($reset->user);
        self::assertSame(0, $reset->loadedEntities);
        self::assertSame(['Review the second change.', 'Answer to Review the second change.'], $this->storedTexts($kernel, $second));
        self::assertSame(['Review the first change.', 'Answer to Review the first change.'], $this->storedTexts($kernel, $first));
    }

    public function testWithoutTheTokenStorageResetAReviewerRunLeavesTheUserForTheNextRequest(): void
    {
        $kernel = $this->bootedKernel(new WorkerKernel('test', true, 'security.token_storage'));
        $this->emptyDatabase($kernel);
        $this->browserOn($kernel)->request('POST', '/mcp/owner', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::OWNER_TOKEN, 'HTTP_HOST' => 'localhost']);

        $this->runReviewer($kernel, new UuidV7(), 'Review this change.');

        self::assertSame('mcp-owner', $this->nextRequestSees($kernel)->user);
    }

    public function testShuttingTheKernelDownResetsTheServicesItBuilt(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $this->browserOn($kernel)->request('POST', '/mcp/owner', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::OWNER_TOKEN, 'HTTP_HOST' => 'localhost']);
        $tokens = $this->container($kernel)->get('security.token_storage');
        self::assertNotNull($tokens->getToken());

        $kernel->shutdown();

        self::assertNull($tokens->getToken());
    }

    public function testTheReviewerDoesNotBuildItsToolboxFactoryBeforeItReviews(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $container = $this->container($kernel);

        self::assertInstanceOf(ReviewerAgent::class, $container->get(ReviewerAgent::class));
        self::assertFalse($container->initialized(ReviewerMcpToolboxFactory::class));
    }

    public function testAFlashMessageIsShownOnlyOnceToTheBrowserThatCausedIt(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $container = $this->container($kernel);
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $container->set(Ledger::class, $ledger);
        $container->set('command.bus', new StartedRoundBus());
        $container->set(ReplayMarks::class, new ReplayMarks(new ReplayMarkBus()));
        $browser = $this->browserOn($kernel);

        $this->startRoundAs($browser);
        $shown = $browser->request('GET', '/reviews/round-1');
        $again = $browser->request('GET', '/reviews/round-1');

        self::assertCount(1, $shown->filter('.toast'));
        self::assertCount(0, $again->filter('.toast'));
    }

    protected function setUp(): void
    {
        // Both arrays, because the env var processor reads $_ENV first and the dotenv file filled it with an empty token.
        $_ENV['MCP_OWNER_TOKEN'] = $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;
        $this->processBefore = ProcessSettings::now();
    }

    protected function tearDown(): void
    {
        $this->processBefore->restore();
        $_ENV['MCP_OWNER_TOKEN'] = $_SERVER['MCP_OWNER_TOKEN'] = '';
        unset($_SERVER['FRANKENPHP_RESET_KERNEL']);
        $this->kernel?->shutdown();
    }

    private function otherLocale(): void
    {
        $other = 'C' === $this->processBefore->locale ? 'C.UTF-8' : 'C';
        self::assertSame($other, setlocale(LC_ALL, $other));
    }

    private function requestThatRuns(WorkerBrowser $browser, Closure $step): void
    {
        $browser->requestRunning($step, '/mcp/owner', ['HTTP_AUTHORIZATION' => 'Bearer '.self::OWNER_TOKEN, 'HTTP_HOST' => 'localhost']);
    }

    // Read inside a real request, because that is what a request starts with after the kernel restored the process settings at the terminate of the one before.
    #[NoDiscard]
    private function nextRequestSeesProcess(WorkerBrowser $browser): ProcessSettings
    {
        $seen = null;
        $this->requestThatRuns($browser, static function () use (&$seen): void {
            $seen = ProcessSettings::now();
        });
        assert($seen instanceof ProcessSettings);

        return $seen;
    }

    private function bootedKernel(KernelInterface $kernel): KernelInterface
    {
        $kernel->boot();
        $this->kernel = $kernel;

        return $kernel;
    }

    private function browserOn(KernelInterface $kernel): WorkerBrowser
    {
        return new WorkerBrowser($kernel, ['HTTP_HOST' => self::HOST]);
    }

    // The kernel runs its services_resetter at the start of the next handle() after a terminate; calling it here reads the state a new request starts with, because reviewer work is not a request. A kernel cloned per request boots afresh first.
    #[NoDiscard]
    private function nextRequestSees(KernelInterface $kernel): WorkerState
    {
        $kernel->boot();
        $resetter = $kernel->getContainer()->get('services_resetter');
        $resetter->reset();

        return WorkerState::in($kernel);
    }

    private function startRoundAs(KernelBrowser $browser): void
    {
        $page = $browser->request('GET', '/reviews/round-1');
        $token = $page->filter('form.start-review input[name=_token]')->attr('value');

        $browser->request('POST', '/reviews/round-1/start', ['_token' => $token, 'number' => '2']);

        self::assertSame(DashboardRoutes::START_REVIEW, $browser->getRequest()->attributes->get('_route'));
        self::assertSame(303, $browser->getResponse()->getStatusCode());
    }

    private function runReviewer(KernelInterface $kernel, UuidV7 $conversation, string $task): void
    {
        $runs = $this->container($kernel)->get(ReviewerRuns::class);
        self::assertInstanceOf(ReviewerRuns::class, $runs);
        $platform = MockPlatformFactory::createPlatform(static fn (): string => 'Answer to '.$task);

        $bag = $runs->started(MessageBag::forId($conversation, Message::ofUser($task)));
        $runs->agent(new Toolbox([]), $platform, 'model')->call($bag)->asText();
    }

    /**
     * @return list<string>
     */
    private function storedTexts(KernelInterface $kernel, UuidV7 $conversation): array
    {
        $store = $this->container($kernel)->get(MessageStoreInterface::class);
        self::assertInstanceOf(MessageStoreInterface::class, $store);

        return array_map(static fn (MessageInterface $message): string => $message instanceof UserMessage || $message instanceof AssistantMessage ? (string) $message->asText() : '', $store->load($conversation)->getMessages());
    }

    private function container(KernelInterface $kernel): TestContainer
    {
        return $kernel->getContainer()->get('test.service_container');
    }

    private function emptyDatabase(KernelInterface $kernel): void
    {
        EmptyDatabase::in($this->container($kernel));
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        $console->run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());

        $connection = $this->container($kernel)->get('doctrine.dbal.default_connection');
        // MySQL and MariaDB commit implicitly at every DDL statement, which leaves the migrations' transactions counted as open; the next flush would then ask for a savepoint that does not exist.
        $connection->close();
    }

    private function recordAnEvent(KernelInterface $kernel): void
    {
        $ledger = $this->container($kernel)->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{"round":"round-1","project":"alpha","reviewers":2}'))->succeeded());
    }
}
