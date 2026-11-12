<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\ToolCallCount;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\RoundToolCall;
use Intakt\Core\Review\Infrastructure\RoundToolCalls;
use Intakt\Core\Review\Infrastructure\StoredSessionSummaries;
use Intakt\Core\Review\UseCase\ReadVerdicts\RoundNotFound;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewedRepository;
use Intakt\Kernel;
use Intakt\Tests\Core\Mcp\Contract\McpServerClient;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RecordingBus;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

// Calls go through the review server over HTTP, because that is where every reviewer's tool calls are served and recorded, whichever vendor runs it.
#[Group('persistence')]
#[CoversClass(StoredSessionSummaries::class)]
#[CoversClass(RoundToolCalls::class)]
#[CoversClass(RoundToolCall::class)]
#[UsesClass(ReviewedRepository::class)]
#[UsesClass(SessionSummary::class)]
#[UsesClass(ToolCallCount::class)]
final class StoredSessionSummariesTest extends KernelTestCase
{
    private const string FIRST = 'Look at part 1.';

    private Kernel $app;

    private RoundFixture $fixture;

    private ReviewCredential $credential;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        self::assertInstanceOf(Kernel::class, $kernel);
        $this->app = $kernel;
        $this->fixture = new RoundFixture(self::getContainer());
        $credential = self::getContainer()->get(ReviewCredential::class);
        self::assertInstanceOf(ReviewCredential::class, $credential);
        $this->credential = $credential;
    }

    public function testToolCallsAreCountedByKind(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $server = $this->serverFor($round);

        $this->call($server, 'read_file', ['path' => 'RoundFixture.php']);
        $this->call($server, 'list_directory', []);
        $this->call($server, 'read_file', ['path' => 'RecordingBus.php']);

        $summary = $this->summaries()->ofRound($round);

        self::assertEquals([new ToolCallCount('list_directory', 1), new ToolCallCount('read_file', 2)], $summary->toolCalls);
        self::assertSame(3, $summary->totalToolCalls());
    }

    public function testAFileReadTwiceIsListedOnceInTheOrderItWasFirstRead(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $server = $this->serverFor($round);

        $this->call($server, 'read_file', ['path' => 'RoundFixture.php']);
        $this->call($server, 'read_file', ['path' => 'RecordingBus.php']);
        $this->call($server, 'read_file', ['path' => 'RoundFixture.php']);

        self::assertSame(['RoundFixture.php', 'RecordingBus.php'], $this->summaries()->ofRound($round)->filesRead);
    }

    public function testOneFileReadUnderTwoSpellingsIsListedOnce(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $server = $this->serverFor($round);

        $this->call($server, 'read_file', ['path' => 'RoundFixture.php']);
        $this->call($server, 'read_file', ['path' => './RoundFixture.php']);

        self::assertSame(['RoundFixture.php'], $this->summaries()->ofRound($round)->filesRead);
    }

    public function testARefusedReadCountsAsACallButNotAsAFileRead(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();

        $this->call($this->serverFor($round), 'read_file', ['path' => '../outside.php']);

        $summary = $this->summaries()->ofRound($round);
        self::assertEquals([new ToolCallCount('read_file', 1)], $summary->toolCalls);
        self::assertSame([], $summary->filesRead);
    }

    public function testASearchOrAListingReadsNoFile(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $server = $this->serverFor($round);

        $this->call($server, 'list_directory', []);
        $this->call($server, 'search_files', ['text' => 'final readonly class', 'path' => 'RoundFixture.php']);

        self::assertSame([], $this->summaries()->ofRound($round)->filesRead);
    }

    public function testTheCallsOfAnotherRoundAreNotCounted(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $other = $this->fixture->startFor('feat/other', new RecordingBus(), 1);

        $this->call($this->serverFor($other), 'read_file', ['path' => 'RoundFixture.php']);

        $summary = $this->summaries()->ofRound($round);
        self::assertSame([], $summary->toolCalls);
        self::assertSame([], $summary->filesRead);
        self::assertSame(0, $summary->totalToolCalls());
    }

    public function testTheCostIsTheRoundsCost(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();

        self::assertEquals(Cost::inUsd(0.61), $this->summaries(Cost::inUsd(0.61))->ofRound($round)->cost);
    }

    public function testBeforeTheRoundCompletesItListsTheOpenFindingsItChecksAndNothingReportedYet(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a')], [], []));
        $round = $this->fixture->start(1)->id()->toRfc4122();

        $summary = $this->summaries()->ofRound($round);

        self::assertSame(['F1'], $this->ids($summary->checking));
        self::assertSame([], $summary->reported);
        self::assertSame([], $summary->resolved);
    }

    public function testOnceTheRoundCompletesItListsWhatItReportedAndWhatItResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a')], [], []));
        $round = $this->completedRound(new Verdict(Decision::Changes, [$this->finding('b')], ['F1'], []));

        $summary = $this->summaries()->ofRound($round);

        self::assertSame(['F1'], $this->ids($summary->checking));
        self::assertSame(['F2'], $this->ids($summary->reported));
        self::assertSame(['F1'], $this->ids($summary->resolved));
    }

    public function testAnOpenFindingTheRoundLeftUnresolvedIsNotListedAsResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a'), $this->finding('b')], [], []));
        $round = $this->completedRound(new Verdict(Decision::Changes, [], ['F2'], []));

        $summary = $this->summaries()->ofRound($round);

        self::assertSame(['F1', 'F2'], $this->ids($summary->checking));
        self::assertSame(['F2'], $this->ids($summary->resolved));
    }

    public function testOnlyTheResolvedOfTwoOpenFindingsThatReadAlikeIsListedAsResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a', 'Same text'), $this->finding('b', 'Same text')], [], []));
        $round = $this->completedRound(new Verdict(Decision::Changes, [], ['F1'], []));

        $summary = $this->summaries()->ofRound($round);

        self::assertSame(['F1', 'F2'], $this->ids($summary->checking));
        self::assertSame(['F1'], $this->ids($summary->resolved));
    }

    public function testAnUnknownRoundIsReportedAsNotFound(): void
    {
        $this->expectException(RoundNotFound::class);

        $this->summaries()->ofRound('0196f1a0-0000-7000-8000-000000000000');
    }

    private function summaries(?Cost $cost = null): StoredSessionSummaries
    {
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn($cost ?? Cost::none());

        return new StoredSessionSummaries($this->fixture->transactions(), $this->fixture->findingStore(), $costs, new RoundToolCalls($this->fixture->entities));
    }

    private function completedRound(Verdict $verdict): string
    {
        $queue = new RecordingBus();
        $round = $this->fixture->startFor(RoundFixture::CHANGE, $queue, 1);
        $vendor = new VerdictPerRole([self::FIRST => $verdict]);

        foreach ($queue->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $round;
    }

    private function serverFor(string $round): McpServerClient
    {
        $client = new McpServerClient($this->app, '/mcp/review', $this->credential->issueFor($round));
        $client->initialize('2025-11-25');

        return $client;
    }

    /**
     * @param array<string, string> $arguments
     */
    private function call(McpServerClient $server, string $tool, array $arguments): void
    {
        $server->call('tools/call', ['name' => $tool, 'arguments' => $arguments], 3);
    }

    private function finding(string $id, ?string $text = null): Finding
    {
        return new Finding($id, Severity::P2, 'src/A.php', 3, null, $text ?? 'Text '.$id, FixableBy::Builder, false, null, null);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function ids(array $findings): array
    {
        return array_map(static fn (Finding $finding): string => $finding->id, $findings);
    }
}
