<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Domain\ReviewSummary;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\MercureLiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\PublishProjectFilter;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Review\Contract\RoundOpened;
use Intakt\Tests\Support\ListedReviews;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversClass(PublishProjectFilter::class)]
#[UsesClass(FilterProjects::class)]
#[UsesClass(LedgerReviews::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(ProjectSummary::class)]
#[UsesClass(ReviewSummary::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(MercureLiveUpdates::class)]
#[UsesClass(LiveEvent::class)]
#[UsesClass(RoundOpened::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(LedgerEvent::class)]
final class PublishProjectFilterTest extends TestCase
{
    // A simulated hub on its own address, reached through Symfony's Hub over a MockHttpClient, so the post is the one a real hub would get.
    private const string HUB = 'http://localhost/.well-known/mercure';

    private const string TOKEN = 'publisher.token.unsigned';

    /**
     * @var list<array<array-key, mixed>>
     */
    private array $posted = [];

    private BufferingLogger $log;

    private ?PublishProjectFilter $secondWorker = null;

    public function testAnOpenedRoundPublishesTheProjectFilterOnTheShellTopicAsAPrivateUpdate(): void
    {
        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('alpha'))(new RoundOpened('r1', 'alpha'));

        self::assertCount(1, $this->posted);
        self::assertSame([DashboardTopics::SHELL, 'on'], [$this->posted[0]['topic'] ?? null, $this->posted[0]['private'] ?? null]);
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testThePublishedFilterUpdatesTheOptionsOfTheShellsProjectSelect(): void
    {
        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('alpha'))(new RoundOpened('r1', 'alpha'));

        self::assertStringStartsWith('<turbo-stream action="update" target="projfilter"><template>', $this->postedStream());
    }

    public function testThePublishedFilterOffersAllProjectsFirstAndThenEveryProjectInNameOrder(): void
    {
        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('beta', 'project10', 'Alpha', 'project9', 'beta'))(new RoundOpened('r4', 'project9'));

        self::assertSame(
            [['', 'All projects'], ['Alpha', 'Alpha'], ['beta', 'beta'], ['project9', 'project9'], ['project10', 'project10']],
            $this->optionsOf($this->postedStream()),
        );
    }

    public function testThePublishedFilterOfTheFirstRoundOffersItsProject(): void
    {
        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('alpha'))(new RoundOpened('r1', 'alpha'));

        self::assertSame([['', 'All projects'], ['alpha', 'alpha']], $this->optionsOf($this->postedStream()));
    }

    public function testAProjectNameIsEscapedInThePublishedFilter(): void
    {
        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('</template><b>"x"</b>'))(new RoundOpened('r1', '</template><b>"x"</b>'));

        self::assertStringContainsString('<option value="&lt;/template&gt;&lt;b&gt;&quot;x&quot;&lt;/b&gt;">&lt;/template&gt;&lt;b&gt;&quot;x&quot;&lt;/b&gt;</option>', $this->postedStream());
        self::assertSame(1, substr_count($this->postedStream(), '</template>'));
    }

    public function testAHubThatIsDownDoesNotFailTheRoundsAnnouncementAndIsLogged(): void
    {
        $down = new MockHttpClient(static fn (): MockResponse => throw new TransportException('Connection refused'));

        $this->publisher($down, $this->ledgerOf('alpha'))(new RoundOpened('r1', 'alpha'));

        self::assertSame(['A live dashboard update was not published.'], array_column($this->log->cleanLogs(), 1));
    }

    public function testAHubThatAnswersWithAnErrorDoesNotFailTheRoundsAnnouncementAndIsLogged(): void
    {
        $this->publisher(new MockHttpClient(new MockResponse('down', ['http_code' => 503])), $this->ledgerOf('alpha'))(new RoundOpened('r1', 'alpha'));

        self::assertSame(['A live dashboard update was not published.'], array_column($this->log->cleanLogs(), 1));
    }

    public function testALedgerThatCannotBeReadPublishesNothingAndIsLoggedWithTheRoundAndItsProject(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willThrowException(new RuntimeException('database is locked'));

        $this->publisher($this->hubThatAccepts(), $ledger)(new RoundOpened('r1', 'alpha'));

        self::assertSame([], $this->posted);
        self::assertSame([['warning', 'The project filter was not published.', 'r1', 'alpha', RuntimeException::class]], $this->loggedWarnings());
    }

    // The second round's worker runs while the first one holds a read from before the second round committed, because a race is proven by forcing its order.
    public function testAFilterReadBeforeAnotherRoundOpenedIsNeverTheLastOnePublished(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $reads = 0;
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturnCallback(function () use (&$reads): RecordedEvents {
            // On the second read, because the first one only decides that alpha's round is its first, and the second is the filter's read under the lock.
            if (2 === ++$reads) {
                ($this->secondWorker ?? throw new LogicException('No second worker.'))(new RoundOpened('r2', 'beta'));

                return $this->eventsOf('alpha');
            }

            return 1 === $reads ? $this->eventsOf('alpha') : $this->eventsOf('alpha', 'beta');
        });
        $this->secondWorker = $this->publisher($this->hubThatAccepts(), $ledger, $locks);

        $this->publisher($this->hubThatAccepts(), $ledger, $locks)(new RoundOpened('r1', 'alpha'));

        self::assertSame([['', 'All projects'], ['alpha', 'alpha'], ['beta', 'beta']], $this->optionsOf($this->postedStream(count($this->posted) - 1)));
    }

    public function testAWorkerThatFindsThePublicationTakenLeavesItToTheWorkerHoldingIt(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $held = $locks->createLock('dashboard-project-filter');
        $held->acquire();

        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('alpha'), $locks)(new RoundOpened('r1', 'alpha'));

        self::assertSame([], $this->posted);
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testAWorkerWhoseFilterIsStillCurrentAfterItLetsGoPublishesItOnce(): void
    {
        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('alpha', 'beta'))(new RoundOpened('r2', 'beta'));

        self::assertCount(1, $this->posted);
    }

    public function testARoundOfAProjectAlreadyInTheFilterPublishesNothing(): void
    {
        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('alpha', 'beta', 'alpha'))(new RoundOpened('r3', 'alpha'));

        self::assertSame([], $this->posted);
        self::assertSame([], $this->log->cleanLogs());
    }

    public function testARoundMissingFromTheLedgerPublishesNothing(): void
    {
        $this->publisher($this->hubThatAccepts(), $this->ledgerOf('alpha'))(new RoundOpened('r9', 'beta'));

        self::assertSame([], $this->posted);
    }

    protected function setUp(): void
    {
        $this->log = new BufferingLogger();
    }

    private function eventsOf(string ...$projects): RecordedEvents
    {
        $events = [];

        foreach (array_values($projects) as $index => $project) {
            $payload = json_encode(['round' => 'r'.($index + 1), 'project' => $project, 'reviewers' => 1], JSON_THROW_ON_ERROR);
            $events[] = new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::ReviewStarted, $payload), new DateTimeImmutable('2026-11-10T12:00:00+00:00'));
        }

        return new RecordedEvents($events);
    }

    private function hubThatAccepts(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str(is_string($options['body'] ?? null) ? $options['body'] : '', $form);
            $this->posted[] = $form;

            return new MockResponse('urn:uuid:'.count($this->posted));
        });
    }

    private function ledgerOf(string ...$projects): Ledger
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn($this->eventsOf(...$projects));

        return $ledger;
    }

    private function publisher(MockHttpClient $hub, Ledger $ledger, LockFactory $locks = new LockFactory(new InMemoryStore())): PublishProjectFilter
    {
        $projects = new FilterProjects(ListedReviews::on($ledger));
        $updates = new MercureLiveUpdates(new Hub(self::HUB, new StaticTokenProvider(self::TOKEN), httpClient: $hub), $this->log);

        return new PublishProjectFilter($projects, new Environment(new FilesystemLoader(__DIR__.'/../../../../templates')), $updates, $locks, $this->log);
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, class-string|null}>
     */
    private function loggedWarnings(): array
    {
        return array_values(array_map(static function (mixed $record): array {
            $record = is_array($record) ? $record : [];
            $context = is_array($record[2] ?? null) ? $record[2] : [];
            $exception = $context['exception'] ?? null;

            return [$record[0] ?? null, $record[1] ?? null, $context['round'] ?? null, $context['project'] ?? null, is_object($exception) ? $exception::class : null];
        }, $this->log->cleanLogs()));
    }

    private function postedStream(int $post = 0): string
    {
        $data = $this->posted[$post]['data'] ?? null;

        return is_string($data) ? $data : '';
    }

    /**
     * @return list<array{string, string}>
     */
    private function optionsOf(string $stream): array
    {
        preg_match_all('#<option value="([^"]*)">([^<]*)</option>#', $stream, $options, PREG_SET_ORDER);

        return array_map(static fn (array $option): array => [$option[1], $option[2]], $options);
    }
}
