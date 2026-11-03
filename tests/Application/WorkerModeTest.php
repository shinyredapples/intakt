<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Doctrine\DBAL\Connection;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Kernel;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use NoDiscard;
use PHPUnit\Framework\Attributes\CoversNothing;
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
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
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

    private ?KernelInterface $kernel = null;

    protected function setUp(): void
    {
        // Both arrays, because the env var processor reads $_ENV first and the dotenv file filled it with an empty token.
        $_ENV['MCP_OWNER_TOKEN'] = $_SERVER['MCP_OWNER_TOKEN'] = self::OWNER_TOKEN;
    }

    protected function tearDown(): void
    {
        $_ENV['MCP_OWNER_TOKEN'] = $_SERVER['MCP_OWNER_TOKEN'] = '';
        $this->kernel?->shutdown();
    }

    public function testARequestWithoutATokenDoesNotSeeTheOwnerOfTheRequestBefore(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $browser = $this->browserOn($kernel);

        $browser->request('POST', '/mcp/owner', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::OWNER_TOKEN, 'HTTP_HOST' => 'localhost']);

        self::assertSame('mcp-owner', WorkerState::in($kernel)->user);
        self::assertNull($this->nextRequestSees($kernel)->user);
    }

    public function testAReviewCredentialDoesNotCarryItsRoundToTheNextRequest(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $browser = $this->browserOn($kernel);
        $round = Uuid::v7()->toRfc4122();
        $credential = $this->container($kernel)->get(ReviewCredential::class);
        self::assertInstanceOf(ReviewCredential::class, $credential);

        $browser->request('POST', '/mcp/review', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$credential->issueFor($round), 'HTTP_HOST' => 'localhost']);

        self::assertSame($round, WorkerState::in($kernel)->user);
        self::assertNull($this->nextRequestSees($kernel)->user);
    }

    public function testAFlashMessageOfOneBrowserIsNotShownToAnotherBrowser(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $container = $this->container($kernel);
        $ledger = new DashboardLedger();
        $ledger->startRecordedRoundOf('feat/thing', 'round-1', 1);
        $container->set(Ledger::class, $ledger);
        $container->set('command.bus', new StartedRoundBus());
        $first = $this->browserOn($kernel);
        $second = $this->browserOn($kernel);

        $this->startRoundAs($first);
        $otherPage = $second->request('GET', '/reviews/round-1');
        $ownPage = $first->request('GET', '/reviews/round-1');

        self::assertCount(0, $otherPage->filter('.flash-success'));
        self::assertSame('Started round round-2.', trim($ownPage->filter('.flash-success')->text()));
    }

    public function testAnEntityLoadedByOneRequestIsNotInTheIdentityMapOfTheNext(): void
    {
        $kernel = $this->bootedKernel(new Kernel('test', true));
        $this->emptyDatabase($kernel);
        $this->recordAnEvent($kernel);
        $this->browserOn($kernel)->request('GET', '/reviews');

        self::assertGreaterThan(0, WorkerState::in($kernel)->loadedEntities);
        self::assertSame(0, $this->nextRequestSees($kernel)->loadedEntities);
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

        $this->runReviewer($kernel, $first, 'Review the first change.');
        $reset = $this->nextRequestSees($kernel);
        $this->runReviewer($kernel, $second, 'Review the second change.');

        self::assertSame(0, $reset->loadedEntities);
        self::assertSame(['Review the second change.', 'Answer to Review the second change.'], $this->storedTexts($kernel, $second));
        self::assertSame(['Review the first change.', 'Answer to Review the first change.'], $this->storedTexts($kernel, $first));
    }

    // Booting after a terminate is what the next handle() does first, so this reads the state a new request starts with.
    #[NoDiscard]
    private function nextRequestSees(KernelInterface $kernel): WorkerState
    {
        $kernel->boot();

        return WorkerState::in($kernel);
    }

    private function bootedKernel(KernelInterface $kernel): KernelInterface
    {
        $kernel->boot();
        $this->kernel = $kernel;

        return $kernel;
    }

    // BrowserKit terminates the kernel after each request, and no reboot keeps the one kernel, as the worker does; each browser has its own cookies and so its own session.
    private function browserOn(KernelInterface $kernel): KernelBrowser
    {
        $browser = new KernelBrowser($kernel, ['HTTP_HOST' => self::HOST]);
        $browser->disableReboot();

        return $browser;
    }

    private function startRoundAs(KernelBrowser $browser): void
    {
        $page = $browser->request('GET', '/reviews/round-1');
        $token = $page->filter('form.start-review input[name=_token]')->attr('value');

        $browser->request('POST', '/reviews/round-1/start', ['_token' => $token, 'number' => '2']);

        self::assertSame(DashboardRoutes::START_REVIEW, $browser->getRequest()->attributes->get('_route'));
        self::assertSame(303, $browser->getResponse()->getStatusCode());
    }

    private function recordAnEvent(KernelInterface $kernel): void
    {
        $ledger = $this->container($kernel)->get(Ledger::class);
        self::assertInstanceOf(Ledger::class, $ledger);
        self::assertTrue($ledger->append(new LedgerEvent(LedgerEventKind::ReviewStarted, '{"round":"round-1","project":"alpha","reviewers":2}'))->succeeded());
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

    private function container(KernelInterface $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        return $container;
    }

    private function emptyDatabase(KernelInterface $kernel): void
    {
        EmptyDatabase::in($this->container($kernel));
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        $console->run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        $console->assertCommandIsSuccessful($console->getDisplay());
        self::assertInstanceOf(Connection::class, $this->container($kernel)->get('doctrine.dbal.default_connection'));
    }
}
