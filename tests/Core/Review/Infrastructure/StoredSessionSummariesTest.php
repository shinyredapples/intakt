<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\ResolvedFinding;
use Doctrine\DBAL\Types\Types;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundUpdates;
use Intakt\Core\Review\Contract\SessionSummary;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\ToolCallCount;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\RoundToolCall;
use Intakt\Core\Review\Infrastructure\RoundToolCalls;
use Intakt\Core\Review\Infrastructure\StoredSessionSummaries;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewCredential;
use Intakt\Core\Review\UseCase\ServeReviewTools\ReviewedRepository;
use Intakt\Kernel;
use Intakt\Tests\Core\Mcp\Contract\McpServerClient;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RecordingBus;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

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

        $summary = $this->summaryOf($round);

        self::assertEquals([new ToolCallCount('list_directory', 1), new ToolCallCount('read_file', 2)], $summary->toolCalls);
        self::assertSame(3, $summary->totalToolCalls());
    }

    public function testEveryToolCallAnnouncesTheChangedSummaryOfItsRound(): void
    {
        $updates = new RecordedRoundUpdates();
        self::getContainer()->set(RoundUpdates::class, $updates);
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $server = $this->serverFor($round);

        $this->call($server, 'read_file', ['path' => 'RoundFixture.php']);
        $this->call($server, 'read_file', ['path' => '../outside.php']);

        self::assertSame(['summary '.$round, 'summary '.$round], $updates->seen);
    }

    public function testAFileReadTwiceIsListedOnceInTheOrderItWasFirstRead(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $server = $this->serverFor($round);

        $this->call($server, 'read_file', ['path' => 'RoundFixture.php']);
        $this->call($server, 'read_file', ['path' => 'RecordingBus.php']);
        $this->call($server, 'read_file', ['path' => 'RoundFixture.php']);

        self::assertSame(['RoundFixture.php', 'RecordingBus.php'], $this->summaryOf($round)->filesRead);
    }

    public function testOneFileReadUnderTwoSpellingsIsListedOnce(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $server = $this->serverFor($round);

        $this->call($server, 'read_file', ['path' => 'RoundFixture.php']);
        $this->call($server, 'read_file', ['path' => './RoundFixture.php']);

        self::assertSame(['RoundFixture.php'], $this->summaryOf($round)->filesRead);
    }

    public function testARefusedReadCountsAsACallButNotAsAFileRead(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();

        $this->call($this->serverFor($round), 'read_file', ['path' => '../outside.php']);

        $summary = $this->summaryOf($round);
        self::assertEquals([new ToolCallCount('read_file', 1)], $summary->toolCalls);
        self::assertSame([], $summary->filesRead);
    }

    public function testASearchOrAListingReadsNoFile(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $server = $this->serverFor($round);

        $this->call($server, 'list_directory', []);
        $this->call($server, 'search_files', ['text' => 'final readonly class', 'path' => 'RoundFixture.php']);

        self::assertSame([], $this->summaryOf($round)->filesRead);
    }

    public function testTheCallsOfAnotherRoundAreNotCounted(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $other = $this->fixture->startFor('feat/other', new RecordingBus(), 1);

        $this->call($this->serverFor($other), 'read_file', ['path' => 'RoundFixture.php']);

        $summary = $this->summaryOf($round);
        self::assertSame([], $summary->toolCalls);
        self::assertSame([], $summary->filesRead);
        self::assertSame(0, $summary->totalToolCalls());
    }

    public function testAReadWhoseRecordingFailsStillAnswersWithTheFile(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $this->recordingFails();

        $result = $this->answerOf($this->serverFor($round), 'read_file', ['path' => 'RoundFixture.php']);

        self::assertFalse($result['isError']);
        self::assertStringContainsString('final readonly class RoundFixture', $result['text']);
        self::assertLoggedError('error', 'A tool call of the review server was not recorded; it is answered anyway.');
    }

    public function testARefusalWhoseRecordingFailsIsStillRefusedWithItsReason(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();
        $this->recordingFails();

        $result = $this->answerOf($this->serverFor($round), 'read_file', ['path' => '../outside.php']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('../outside.php', $result['text']);
        self::assertLoggedError('error', 'A tool call of the review server was not recorded; it is answered anyway.');
    }

    public function testTheCostIsTheRoundsCost(): void
    {
        $round = $this->fixture->start(1)->id()->toRfc4122();

        self::assertEquals(Cost::inUsd(0.61), $this->summaryOf($round, Cost::inUsd(0.61))->cost);
    }

    public function testBeforeTheRoundCompletesItListsTheOpenFindingsItChecksAndNothingReportedYet(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a')], [], []));
        $round = $this->fixture->start(1)->id()->toRfc4122();

        $summary = $this->summaryOf($round);

        self::assertSame(['F1'], $this->ids($summary->checking));
        self::assertSame([], $summary->reported);
        self::assertSame([], $summary->resolved);
    }

    public function testOnceTheRoundCompletesItListsWhatItReportedAndWhatItResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a')], [], []));
        $round = $this->completedRound(new Verdict(Decision::Changes, [$this->finding('b')], [new ResolvedFinding('F1')], []));

        $summary = $this->summaryOf($round);

        self::assertSame(['F1'], $this->ids($summary->checking));
        self::assertSame(['F2'], $this->ids($summary->reported));
        self::assertSame(['F1'], $this->ids($summary->resolved));
    }

    public function testAnOpenFindingTheRoundLeftUnresolvedIsNotListedAsResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a'), $this->finding('b')], [], []));
        $round = $this->completedRound(new Verdict(Decision::Changes, [], [new ResolvedFinding('F2')], []));

        $summary = $this->summaryOf($round);

        self::assertSame(['F1', 'F2'], $this->ids($summary->checking));
        self::assertSame(['F2'], $this->ids($summary->resolved));
    }

    public function testOnlyTheResolvedOfTwoOpenFindingsThatReadAlikeIsListedAsResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a', 'Same text'), $this->finding('b', 'Same text')], [], []));
        $round = $this->completedRound(new Verdict(Decision::Changes, [], [new ResolvedFinding('F1')], []));

        $summary = $this->summaryOf($round);

        self::assertSame(['F1', 'F2'], $this->ids($summary->checking));
        self::assertSame(['F1'], $this->ids($summary->resolved));
    }

    public function testALegacyRoundThatResolvedOneOfTwoFindingsThatReadAlikeUnderAReviewerIdNamingTheOtherListsNoneAsResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a', 'Same text'), $this->finding('b', 'Same text')], [], []));
        $round = $this->completedRound(new Verdict(Decision::Changes, [], [new ResolvedFinding('F1')], []));
        $this->completedBeforeLedgerIds($round, $this->finding('F2', 'Same text'));

        $summary = $this->summaryOf($round);

        self::assertSame(['F1', 'F2'], $this->ids($summary->checking));
        self::assertSame([], $summary->resolved);
    }

    public function testALegacyRoundThatResolvedOneOfTwoFindingsThatReadDifferentlyUnderAReviewerIdNamingTheOtherListsNoneAsResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a', 'First'), $this->finding('b', 'Second')], [], []));
        $round = $this->completedRound(new Verdict(Decision::Changes, [], [new ResolvedFinding('F1')], []));
        $this->completedBeforeLedgerIds($round, $this->finding('F2', 'First'));

        $summary = $this->summaryOf($round);

        self::assertSame(['F1', 'F2'], $this->ids($summary->checking));
        self::assertSame([], $summary->resolved);
    }

    public function testAFindingResolvedAfterAnEarlierOpenRoundRepeatedItWithNewTextIsListedAsResolvedAsTheRoundCheckedIt(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a', 'Old text')], [], []));
        $earlier = $this->startedRound();
        $later = $this->startedRound();

        $this->finish($earlier, new Verdict(Decision::Changes, [new Finding('F1', Severity::P2, 'src/A.php', 3, null, 'New text', FixableBy::Builder, true, null, null)], [], []));
        $this->finish($later, new Verdict(Decision::Changes, [], [new ResolvedFinding('F1')], []));

        $resolved = $this->summaryOf($later['round'])->resolved;
        self::assertSame(['F1'], $this->ids($resolved));
        self::assertSame('Old text', $resolved[0]->text);
    }

    public function testARoundCompletedAfterANewerRoundOfTheChangeListsNoneAsResolved(): void
    {
        $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a')], [], []));
        $earlier = $this->startedRound();
        $later = $this->startedRound();

        $this->finish($later, new Verdict(Decision::Changes, [], [], []));
        $this->finish($earlier, new Verdict(Decision::Changes, [], [new ResolvedFinding('F1')], []));

        self::assertSame([], $this->summaryOf($earlier['round'])->resolved);
    }

    public function testTheSummaryOfARoundStillUnderReviewIsNotJudged(): void
    {
        $round = $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a')], [], []));

        self::assertFalse($this->summaryOf($round)->judged);
    }

    public function testTheSummaryOfAJudgedRoundIsJudged(): void
    {
        $round = $this->completedRound(new Verdict(Decision::Changes, [$this->finding('a')], [], []));
        $this->fixture->judge(new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)])))(new JudgeRound($round));

        self::assertTrue($this->summaryOf($round)->judged);
    }

    public function testAnUnknownRoundHasNoSummary(): void
    {
        self::assertNull($this->summaries()->ofRound('0196f1a0-0000-7000-8000-000000000000'));
    }

    public function testARoundIdThatIsNoUuidHasNoSummary(): void
    {
        self::assertNull($this->summaries()->ofRound('round-1'));
    }

    private function summaryOf(string $roundId, ?Cost $cost = null): SessionSummary
    {
        $summary = $this->summaries($cost)->ofRound($roundId);
        self::assertInstanceOf(SessionSummary::class, $summary);

        return $summary;
    }

    private function summaries(?Cost $cost = null): StoredSessionSummaries
    {
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn($cost ?? Cost::none());

        return new StoredSessionSummaries($this->fixture->transactions(), $this->fixture->findingStore(), $costs, new RoundToolCalls($this->fixture->entities, new NullLogger()));
    }

    private function completedRound(Verdict $verdict): string
    {
        $started = $this->startedRound();
        $this->finish($started, $verdict);

        return $started['round'];
    }

    /**
     * @return array{round: string, queue: RecordingBus}
     */
    private function startedRound(): array
    {
        $queue = new RecordingBus();

        return ['round' => $this->fixture->startFor(RoundFixture::CHANGE, $queue, 1), 'queue' => $queue];
    }

    /**
     * @param array{round: string, queue: RecordingBus} $started
     */
    private function finish(array $started, Verdict $verdict): void
    {
        $vendor = new VerdictPerRole([self::FIRST => $verdict]);

        foreach ($started['queue']->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
    }

    // Written back through SQL, because the round has no way to forget its ledger ids, and a round completed before migration Version20261112110000 kept none and its resolved finding under its reviewer's id.
    private function completedBeforeLedgerIds(string $round, Finding $kept): void
    {
        $this->fixture->entities->getConnection()->executeStatement(
            'UPDATE review_round SET resolved_findings = ?, resolved_finding_ids = NULL WHERE id = ?',
            [$this->storedSerializer()->serialize([$kept], JsonEncoder::FORMAT), Uuid::fromString($round)],
            [Types::TEXT, UuidType::NAME],
        );
        $this->fixture->entities->clear();
    }

    private function storedSerializer(): SerializerInterface
    {
        $serializer = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
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

    /**
     * @param array<string, string> $arguments
     *
     * @return array{isError: bool, text: string}
     */
    private function answerOf(McpServerClient $server, string $tool, array $arguments): array
    {
        /** @var array{result: array{isError?: bool, content: list<array{text: string}>}} $response */
        $response = $server->call('tools/call', ['name' => $tool, 'arguments' => $arguments], 3);

        return ['isError' => $response['result']['isError'] ?? false, 'text' => $response['result']['content'][0]['text']];
    }

    // The table is dropped, because then the insert fails in the database itself, as it would when the database refuses it.
    private function recordingFails(): void
    {
        $this->fixture->entities->getConnection()->executeStatement('DROP TABLE round_tool_call');
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
