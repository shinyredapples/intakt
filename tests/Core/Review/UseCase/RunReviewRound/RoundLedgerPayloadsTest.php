<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\ProjectNotConfigured;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationReviewFailed;
use Intakt\Core\Review\Contract\EscalationVerdictKept;
use Intakt\Core\Review\Contract\EscalationVerdictReused;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\NearLine;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\QuoteNearLines;
use Intakt\Core\Review\Contract\RecurringP1Found;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\SameCause;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Review\Domain\FindingKey;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\Infrastructure\FindingKeys;
use Intakt\Core\Review\Infrastructure\StoredFindingIdsNormalizer;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Core\ReviewerForLoop\Infrastructure\ConfiguredProjectModes;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(ReviewStarted::class)]
#[CoversClass(ReviewerStarted::class)]
#[CoversClass(VerdictReceived::class)]
#[CoversClass(CatchUpOwed::class)]
#[CoversClass(ReviewerFailed::class)]
#[CoversClass(ReviewerBlocked::class)]
#[CoversClass(ReviewerStopped::class)]
#[CoversClass(JudgeDone::class)]
#[CoversClass(JudgeDoneFinding::class)]
#[CoversClass(DroppedFinding::class)]
#[CoversClass(QuoteNearLines::class)]
#[CoversClass(NearLine::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(RecurringP1Found::class)]
#[CoversClass(EscalationVerdictReused::class)]
#[CoversClass(EscalationVerdictKept::class)]
#[CoversClass(EscalationReviewFailed::class)]
#[UsesClass(OnePerPlace::class)]
#[UsesClass(FindingKeys::class)]
#[UsesClass(FindingKey::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(JudgedFindings::class)]
#[UsesClass(FindingIds::class)]
#[UsesClass(SameCause::class)]
#[UsesClass(StoredFindingIdsNormalizer::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(ProjectNotConfigured::class)]
#[UsesClass(ConfiguredTierResolver::class)]
final class RoundLedgerPayloadsTest extends KernelTestCase
{
    private const string ROUND = '0195f0a2-7c1e-7000-8000-000000000001';

    private const string REVIEWER = '0195f0a2-7c1e-7000-8000-000000000002';

    private const string MERGED_ROW = '{"round":"'.self::ROUND.'","act":["F1","F3"],"follow_ups":["F4"],"findings":[{"id":"F1","severity":"P1","text":"A bug.","merged":["F3"],"file":null,"line":null,"judge_note":null}],"dropped":[],"same_cause":{"F3":"F1"},"votes":[],"p1_keys":[],"fallback":null}';

    private const string IDENTITY = '{"round":"'.self::ROUND.'","reviewer":"'.self::REVIEWER.'","role":"security","tier":"strong"';

    public function testAStartWithASourceIsWrittenInTodaysFormat(): void
    {
        $started = new ReviewStarted(self::ROUND, 'alpha', 2, 'feat/x', 3, 'T7', '/repos/alpha', 'main');

        self::assertSame('{"round":"'.self::ROUND.'","project":"alpha","reviewers":2,"change":"feat\/x","number":3,"task":"T7","repository":"\/repos\/alpha","base":"main"}', $this->written($started));
    }

    public function testAStartWithAQuorumIsWrittenWithTodaysPolicyValue(): void
    {
        $started = new ReviewStarted(self::ROUND, 'alpha', 2, 'feat/x', 3, 'T7', quorum: QuorumPolicy::OneStrongPlusOne);

        self::assertSame('{"round":"'.self::ROUND.'","project":"alpha","reviewers":2,"change":"feat\/x","number":3,"task":"T7","quorum":"one-strong-plus-one"}', $this->written($started));
    }

    public function testAStartWithoutASourceHasNoRepositoryOrBaseKey(): void
    {
        $started = new ReviewStarted(self::ROUND, 'alpha', 1, 'feat/x', 1, 'T7');

        self::assertSame('{"round":"'.self::ROUND.'","project":"alpha","reviewers":1,"change":"feat\/x","number":1,"task":"T7"}', $this->written($started));
    }

    public function testAStartedReviewerWithoutAModelIsWrittenWithItsIdentityAlone(): void
    {
        self::assertSame(self::IDENTITY.'}', $this->written(new ReviewerStarted(self::ROUND, self::REVIEWER, 'security', Tier::Strong)));
    }

    public function testAStartedReviewerWithAModelIsWrittenWithItsModelAfterItsIdentity(): void
    {
        self::assertSame(self::IDENTITY.',"model":"strong-model"}', $this->written(new ReviewerStarted(self::ROUND, self::REVIEWER, 'security', Tier::Strong, 'strong-model')));
    }

    public function testAStartedReviewerRowWrittenBeforeTheModelWasRecordedReadsWithNoModel(): void
    {
        $started = $this->serializer()->deserialize(self::IDENTITY.'}', ReviewerStarted::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(ReviewerStarted::class, $started);
        self::assertSame(['security', null], [$started->role, $started->model]);
    }

    public function testAReviewersStartRecordsTheModelItsTierResolvesTo(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::Anthropic, 'resolved-model'));
        $ledger = new InMemoryLedger();

        new RoundLedger($ledger, new ConfiguredProjectModes([]), $this->serializer(), $tiers, $this->redactor(), new FindingKeys())->reviewerStarted($reviewer);

        self::assertSame('resolved-model', $ledger->payloads()[0]['model']);
    }

    public function testAReviewerWhoseTierDoesNotResolveIsStillRecordedAsStartedWithoutAModel(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willThrowException(new ProjectNotConfigured('alpha'));
        $ledger = new InMemoryLedger();

        new RoundLedger($ledger, new ConfiguredProjectModes([]), $this->serializer(), $tiers, $this->redactor(), new FindingKeys())->reviewerStarted($reviewer);

        self::assertSame(['round', 'reviewer', 'role', 'tier'], array_keys($ledger->payloads()[0]));
    }

    public function testAReviewersStartIsAppendedWithItsRoundAsTheSubjectAndAVerdictWithoutOne(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $ledger = new InMemoryLedger();
        $rounds = $this->roundLedger($ledger);

        $rounds->reviewerStarted($reviewer);
        $rounds->verdictReceived($reviewer, Verdict::noTools());

        self::assertSame([$reviewer->round()->id()->toRfc4122(), null], $ledger->subjects());
    }

    public function testOnlyTheStartOfAReviewerCarriesItsModel(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $ledger = new InMemoryLedger();

        $this->roundLedger($ledger)->reviewerStopped($reviewer);

        self::assertArrayNotHasKey('model', $ledger->payloads()[0]);
    }

    public function testAReceivedVerdictIsWrittenWithTodaysDecisionAndModeValues(): void
    {
        $received = new VerdictReceived(self::ROUND, self::REVIEWER, 'security', Tier::Strong, Decision::Changes, 2, ProjectMode::ReviewerForLoop);

        self::assertSame(self::IDENTITY.',"decision":"changes","findings":2,"mode":"reviewer-for-loop"}', $this->written($received));
    }

    public function testABlockedVerdictIntaktGaveIsWrittenWithItsReason(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $ledger = new InMemoryLedger();

        $this->roundLedger($ledger)->verdictReceived($reviewer, Verdict::noTools());

        self::assertSame(Verdict::noTools()->findings[0]->text, $ledger->payloads()[0]['reason']);
    }

    public function testAReceivedVerdictWrittenBeforeReasonsWereRecordedReadsWithNoReason(): void
    {
        $received = $this->serializer()->deserialize(self::IDENTITY.',"decision":"blocked","findings":1,"mode":"live"}', VerdictReceived::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(VerdictReceived::class, $received);
        self::assertNull($received->reason);
    }

    public function testAnOwedCatchUpIsWrittenWithItsSubstitute(): void
    {
        $owed = new CatchUpOwed(self::ROUND, self::REVIEWER, 'security', Tier::Strong, Provider::OpenAi);

        self::assertSame(self::IDENTITY.',"substitute":"openai"}', $this->written($owed));
    }

    public function testAnOwedCatchUpWithAModelIsWrittenWithTheModelAfterItsSubstitute(): void
    {
        $owed = new CatchUpOwed(self::ROUND, self::REVIEWER, 'security', Tier::Strong, Provider::OpenAi, 'other-model');

        self::assertSame(self::IDENTITY.',"substitute":"openai","model":"other-model"}', $this->written($owed));
    }

    public function testAnOwedCatchUpRowWrittenBeforeTheModelWasRecordedReadsWithNoModel(): void
    {
        $owed = $this->serializer()->deserialize(self::IDENTITY.',"substitute":"openai"}', CatchUpOwed::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(CatchUpOwed::class, $owed);
        self::assertSame([Provider::OpenAi, null], [$owed->substitute, $owed->model]);
    }

    public function testAnOwedCatchUpRecordsTheModelOfTheTiersSubstituteOnThatProvider(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('substitutes')->willReturn([new ResolvedModel(Provider::ClaudeCode, 'code-model'), new ResolvedModel(Provider::OpenAi, 'openai-model'), new ResolvedModel(Provider::OpenAi, 'later-model')]);
        $ledger = new InMemoryLedger();

        new RoundLedger($ledger, new ConfiguredProjectModes([]), $this->serializer(), $tiers, $this->redactor(), new FindingKeys())->catchUpOwed($reviewer, Provider::OpenAi);

        self::assertSame(['openai', 'openai-model'], [$ledger->payloads()[0]['substitute'], $ledger->payloads()[0]['model']]);
    }

    public function testAnOwedCatchUpWhoseSubstituteIsNoLongerConfiguredRecordsNoModel(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('substitutes')->willReturn([new ResolvedModel(Provider::ClaudeCode, 'code-model')]);
        $ledger = new InMemoryLedger();

        new RoundLedger($ledger, new ConfiguredProjectModes([]), $this->serializer(), $tiers, $this->redactor(), new FindingKeys())->catchUpOwed($reviewer, Provider::OpenAi);

        self::assertArrayNotHasKey('model', $ledger->payloads()[0]);
    }

    public function testAnOwedCatchUpWhoseTierDoesNotResolveIsStillRecordedWithoutAModel(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('substitutes')->willThrowException(new ProjectNotConfigured('alpha'));
        $ledger = new InMemoryLedger();

        new RoundLedger($ledger, new ConfiguredProjectModes([]), $this->serializer(), $tiers, $this->redactor(), new FindingKeys())->catchUpOwed($reviewer, Provider::OpenAi);

        self::assertSame(['round', 'reviewer', 'role', 'tier', 'substitute'], array_keys($ledger->payloads()[0]));
    }

    public function testAFailedReviewerIsWrittenWithItsFailure(): void
    {
        $failed = new ReviewerFailed(self::ROUND, self::REVIEWER, 'security', Tier::Strong, 'RuntimeException');

        self::assertSame(self::IDENTITY.',"failure":"RuntimeException"}', $this->written($failed));
    }

    public function testABlockedReviewerIsWrittenWithItsReason(): void
    {
        $blocked = new ReviewerBlocked(self::ROUND, self::REVIEWER, 'security', Tier::Strong, 'no-tools');

        self::assertSame(self::IDENTITY.',"reason":"no-tools"}', $this->written($blocked));
    }

    public function testAStoppedReviewerIsWrittenWithItsIdentity(): void
    {
        self::assertSame(self::IDENTITY.'}', $this->written(new ReviewerStopped(self::ROUND, self::REVIEWER, 'security', Tier::Strong)));
    }

    public function testADoneJudgeIsWrittenWithItsActListFollowUpsAndFindings(): void
    {
        $done = new JudgeDone(self::ROUND, new FindingIds('f1'), new FindingIds('f2'), [new JudgeDoneFinding('f1', Severity::P1, 'A bug.'), new JudgeDoneFinding('f2', Severity::P3, 'A nit.')]);

        self::assertSame('{"round":"'.self::ROUND.'","act":["f1"],"follow_ups":["f2"],"findings":[{"id":"f1","severity":"P1","text":"A bug.","merged":[],"file":null,"line":null,"judge_note":null},{"id":"f2","severity":"P3","text":"A nit.","merged":[],"file":null,"line":null,"judge_note":null}],"dropped":[],"same_cause":[],"votes":[],"p1_keys":[],"fallback":null}', $this->written($done));
    }

    public function testADoneJudgeListsEachDroppedFindingWithItsIdPlaceAndReason(): void
    {
        $round = new RoundFixture(self::getContainer())->start(1);
        $ledger = new InMemoryLedger();
        $dropped = DroppedFinding::of($this->findingAt('F1', Severity::P1, 7), 'none of its 1 quoted code fragments is in the change');

        $this->roundLedger($ledger)->judgeDone($round, JudgedFindings::of(new Judgement([]), [], [$dropped]), []);

        self::assertSame(
            [['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'file' => 'src/A.php', 'line' => 7, 'reason' => 'none of its 1 quoted code fragments is in the change', 'nearest' => []]],
            $ledger->payloads()[0]['dropped'],
        );
    }

    public function testADroppedFindingWithNearestLinesIsWrittenAndReadsBackWithThem(): void
    {
        $round = new RoundFixture(self::getContainer())->start(1);
        $ledger = new InMemoryLedger();
        $dropped = DroppedFinding::of($this->findingAt('F1', Severity::P1, 7), 'none of its 1 quoted code fragments is in the change', [new QuoteNearLines('dropReason', [new NearLine('src/A.php', 3, '$reason = 1;')])]);

        $this->roundLedger($ledger)->judgeDone($round, JudgedFindings::of(new Judgement([]), [], [$dropped]), []);
        $payload = $ledger->payloads()[0];
        $done = $this->serializer()->deserialize((string) json_encode($payload), JudgeDone::class, JsonEncoder::FORMAT);

        self::assertSame([['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'file' => 'src/A.php', 'line' => 7, 'reason' => 'none of its 1 quoted code fragments is in the change', 'nearest' => [['quote' => 'dropReason', 'lines' => [['file' => 'src/A.php', 'line' => 3, 'text' => '$reason = 1;']]]]]], $payload['dropped']);
        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertEquals([$dropped], $done->dropped);
    }

    public function testAJudgeRowWithADroppedFindingReadsBackWithItsReason(): void
    {
        $done = $this->serializer()->deserialize('{"round":"'.self::ROUND.'","act":[],"follow_ups":[],"findings":[],"dropped":[{"id":"F1","severity":"P1","text":"A bug.","file":"src/A.php","line":7,"reason":"none of its 1 quoted code fragments is in the change"}]}', JudgeDone::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertEquals([new DroppedFinding('F1', Severity::P1, 'A bug.', 'src/A.php', 7, 'none of its 1 quoted code fragments is in the change')], $done->dropped);
    }

    public function testADroppedFindingWrittenBeforeNearestLinesWereRecordedReadsWithNone(): void
    {
        $done = $this->serializer()->deserialize('{"round":"'.self::ROUND.'","act":[],"follow_ups":[],"findings":[],"dropped":[{"id":"F1","severity":"P1","text":"A bug.","file":"src/A.php","line":7,"reason":"why"}]}', JudgeDone::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertSame([], $done->dropped[0]->nearest);
    }

    public function testAJudgeRowWrittenBeforeDropReasonsWereRecordedReadsWithNoDroppedFindings(): void
    {
        $done = $this->serializer()->deserialize('{"round":"'.self::ROUND.'","act":[],"follow_ups":[],"findings":[]}', JudgeDone::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertSame([], $done->dropped);
    }

    public function testADoneJudgeRecordsTwoActFindingsAtOnePlaceAsTheActEntryThatNamesTheMergedId(): void
    {
        $round = new RoundFixture(self::getContainer())->start(1);
        $ledger = new InMemoryLedger();
        $findings = [$this->findingAt('F1', Severity::P2, 7), $this->findingAt('F2', Severity::P1, 7), $this->findingAt('F3', Severity::P3, 8)];
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Act), new JudgedFinding('F3', Mark::Defer)]);

        $this->roundLedger($ledger)->judgeDone($round, JudgedFindings::of($judgement, $findings), []);

        $payload = $ledger->payloads()[0];
        self::assertSame(['F1', 'F2'], $payload['act']);
        self::assertSame([
            ['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'merged' => ['F2'], 'file' => 'src/A.php', 'line' => 7, 'judge_note' => null],
            ['id' => 'F2', 'severity' => 'P1', 'text' => 'Text of F2.', 'merged' => [], 'file' => 'src/A.php', 'line' => 7, 'judge_note' => null],
            ['id' => 'F3', 'severity' => 'P3', 'text' => 'Text of F3.', 'merged' => [], 'file' => 'src/A.php', 'line' => 8, 'judge_note' => null],
        ], $payload['findings']);
    }

    public function testAP2MergedIntoAnActEntryAtOnePlaceKeepsItsJudgesNoteOnItsOwnRecord(): void
    {
        $round = new RoundFixture(self::getContainer())->start(1);
        $ledger = new InMemoryLedger();
        $findings = [$this->findingAt('F1', Severity::P1, 7), $this->findingAt('F2', Severity::P2, 7)];
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer, note: 'Not reachable.')]);

        $this->roundLedger($ledger)->judgeDone($round, JudgedFindings::of($judgement, $findings), []);

        self::assertSame([
            ['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of F1.', 'merged' => ['F2'], 'file' => 'src/A.php', 'line' => 7, 'judge_note' => null],
            ['id' => 'F2', 'severity' => 'P2', 'text' => 'Text of F2.', 'merged' => [], 'file' => 'src/A.php', 'line' => 7, 'judge_note' => 'Not reachable.'],
        ], $ledger->payloads()[0]['findings']);
    }

    public function testAJudgeRowWrittenWithoutFileAndLineStillReadsWithBothNull(): void
    {
        $finding = self::getContainer()->get('serializer.stored')->deserialize('{"id":"f1","severity":"P1","text":"A bug."}', JudgeDoneFinding::class, JsonEncoder::FORMAT);

        self::assertSame([null, null], [$finding->file, $finding->line]);
    }

    public function testAJudgeRowWrittenWithoutAJudgesNoteReadsWithANullNote(): void
    {
        $finding = self::getContainer()->get('serializer.stored')->deserialize('{"id":"f1","severity":"P1","text":"A bug.","merged":[],"file":null,"line":null}', JudgeDoneFinding::class, JsonEncoder::FORMAT);

        self::assertNull($finding->judgeNote);
    }

    public function testAJudgeRowWithAJudgesNoteReadsBackWithIt(): void
    {
        $finding = self::getContainer()->get('serializer.stored')->deserialize('{"id":"f1","severity":"P1","text":"A bug.","judge_note":"Not reachable."}', JudgeDoneFinding::class, JsonEncoder::FORMAT);

        self::assertSame('Not reachable.', $finding->judgeNote);
    }

    public function testARecurringP1FoundIsWrittenWithItsRoundFindingKeyAndFirstRound(): void
    {
        self::assertSame('{"round":"'.self::ROUND.'","finding":"F1","key":"abc","first_round":"r1"}', $this->written(new RecurringP1Found(self::ROUND, 'F1', 'abc', 'r1')));
    }

    public function testAnEscalationVerdictReusedIsWrittenWithItsRoundFindingKeyOutcomeAndVerdictRound(): void
    {
        self::assertSame('{"round":"'.self::ROUND.'","finding":"F1","key":"abc","outcome":"confirmed","verdict_round":"r1"}', $this->written(new EscalationVerdictReused(self::ROUND, 'F1', 'abc', EscalationOutcome::Confirmed, 'r1')));
    }

    public function testAnEscalationVerdictKeptIsWrittenWithItsRoundFindingKeyOutcomeAndReasons(): void
    {
        self::assertSame('{"round":"'.self::ROUND.'","finding":"F1","key":"abc","outcome":"rejected","reasons":"The retry delivers it."}', $this->written(new EscalationVerdictKept(self::ROUND, 'F1', 'abc', EscalationOutcome::Rejected, 'The retry delivers it.')));
    }

    public function testAnEscalationReviewFailedIsWrittenWithItsRoundFindingKeyAndReason(): void
    {
        self::assertSame('{"round":"'.self::ROUND.'","finding":"F1","key":"abc","reason":"the answer could not be read as an escalation verdict"}', $this->written(new EscalationReviewFailed(self::ROUND, 'F1', 'abc', 'the answer could not be read as an escalation verdict')));
    }

    public function testADoneJudgeWithoutFollowUpsStillWritesTheKey(): void
    {
        self::assertSame('{"round":"'.self::ROUND.'","act":[],"follow_ups":[],"findings":[],"dropped":[],"same_cause":[],"votes":[],"p1_keys":[],"fallback":null}', $this->written(new JudgeDone(self::ROUND, new FindingIds(), new FindingIds(), [])));
    }

    public function testADoneJudgeWithMergedIdsIsWrittenInTodaysFormat(): void
    {
        $done = new JudgeDone(self::ROUND, new FindingIds('F1', 'F3'), new FindingIds('F4'), [new JudgeDoneFinding('F1', Severity::P1, 'A bug.', new FindingIds('F3'))], [], new SameCause()->with('F3', 'F1'));

        self::assertSame(self::MERGED_ROW, $this->written($done));
    }

    public function testAJudgeRowWrittenBeforeIdsWereOneValueReadsBackAndIsWrittenAgainUnchanged(): void
    {
        $done = $this->serializer()->deserialize(self::MERGED_ROW, JudgeDone::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertSame([['F1', 'F3'], ['F4'], ['F3'], 'F1'], [[...$done->act], [...$done->followUps], [...$done->findings[0]->merged], $done->sameCause->causeOf('F3')]);
        self::assertSame(self::MERGED_ROW, $this->written($done));
    }

    public function testAJudgeRowWithAFindingAsItsOwnCauseReadsBackAndIsWrittenAgainUnchanged(): void
    {
        $row = '{"round":"'.self::ROUND.'","act":["F1"],"follow_ups":[],"findings":[],"dropped":[],"same_cause":{"F1":"F1"},"votes":[],"p1_keys":[],"fallback":null}';

        $done = $this->serializer()->deserialize($row, JudgeDone::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertSame($row, $this->written($done));
    }

    public function testAJudgeRowWithAKeyedActListReadsInOrderAndIsWrittenBackAsAList(): void
    {
        $done = $this->serializer()->deserialize('{"round":"'.self::ROUND.'","act":{"3":"F3","1":"F1"},"follow_ups":[],"findings":[],"dropped":[],"same_cause":[],"votes":[],"p1_keys":[],"fallback":null}', JudgeDone::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertSame('{"round":"'.self::ROUND.'","act":["F3","F1"],"follow_ups":[],"findings":[],"dropped":[],"same_cause":[],"votes":[],"p1_keys":[],"fallback":null}', $this->written($done));
    }

    public function testAJudgeRowWrittenBeforeActEntriesWereRecordedReadsWithNoMergedIds(): void
    {
        $finding = self::getContainer()->get('serializer.stored')->deserialize('{"id":"f1","severity":"P1","text":"A bug."}', JudgeDoneFinding::class, JsonEncoder::FORMAT);

        self::assertSame([], [...$finding->merged]);
    }

    public function testADoneJudgeRecordsAKeyForEachP1FindingAndNullForOneNamingNoReadableFile(): void
    {
        $round = new RoundFixture(self::getContainer())->start(1);
        $ledger = new InMemoryLedger();
        $keyed = new Finding('F2', Severity::P1, null, null, null, 'Released early in RoundFixture.php.', FixableBy::Builder, false, null, null);
        $findings = [$this->findingAt('F1', Severity::P1, 7), $keyed, $this->findingAt('F3', Severity::P2, 9)];

        $this->roundLedger($ledger)->judgeDone($round, JudgedFindings::of(new Judgement([]), $findings), []);

        self::assertSame(['F1' => null, 'F2' => new FindingKeys()->of($keyed, __DIR__)?->value], $ledger->payloads()[0]['p1_keys']);
        self::assertNotNull($ledger->payloads()[0]['p1_keys']['F2']);
    }

    public function testAJudgeRowWithP1KeysReadsBackWithThem(): void
    {
        $done = $this->serializer()->deserialize('{"round":"'.self::ROUND.'","act":[],"follow_ups":[],"findings":[],"p1_keys":{"F1":"abc","F2":null}}', JudgeDone::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertSame(['F1' => 'abc', 'F2' => null], $done->p1Keys);
    }

    public function testAJudgeRowWrittenBeforeKeysWereRecordedReadsWithNoKeys(): void
    {
        $done = $this->serializer()->deserialize('{"round":"'.self::ROUND.'","act":[],"follow_ups":[],"findings":[]}', JudgeDone::class, JsonEncoder::FORMAT);

        self::assertInstanceOf(JudgeDone::class, $done);
        self::assertSame([], $done->p1Keys);
    }

    public function testADoneJudgeRecordsEachReviewersCountedVoteAndReadsItBack(): void
    {
        $written = $this->written(new JudgeDone(self::ROUND, new FindingIds(), new FindingIds(), [], votes: ['reviewer-a' => Decision::Approve, 'reviewer-b' => Decision::Blocked]));

        $done = $this->serializer()->deserialize($written, JudgeDone::class, JsonEncoder::FORMAT);

        self::assertStringContainsString('"votes":{"reviewer-a":"approve","reviewer-b":"blocked"}', $written);
        self::assertSame(['reviewer-a' => Decision::Approve, 'reviewer-b' => Decision::Blocked], $done->votes);
    }

    public function testAJudgeRowWrittenBeforeVotesWereCountedReadsWithNoVotes(): void
    {
        $done = $this->serializer()->deserialize('{"round":"'.self::ROUND.'","act":[],"follow_ups":[],"findings":[]}', JudgeDone::class, JsonEncoder::FORMAT);

        self::assertSame([], $done->votes);
    }

    public function testTheLedgerSpreadsTheReviewerIdentityBeforeTheEventsOwnKeys(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $ledger = new InMemoryLedger();

        $this->roundLedger($ledger)->reviewerFailed($reviewer, 'RuntimeException');

        $payload = $ledger->payloads()[0];
        self::assertSame(['round', 'reviewer', 'role', 'tier', 'failure'], array_keys($payload));
        self::assertSame([$reviewer->round()->id()->toRfc4122(), $reviewer->id()->toRfc4122()], [$payload['round'], $payload['reviewer']]);
    }

    private function roundLedger(InMemoryLedger $ledger): RoundLedger
    {
        return new RoundLedger($ledger, new ConfiguredProjectModes([]), $this->serializer(), new ConfiguredTierResolver(['alpha' => ['tiers' => ['standard' => ['provider' => 'anthropic', 'model' => 'standard-model']]]], []), $this->redactor(), new FindingKeys());
    }

    private function redactor(): Redactor
    {
        return self::getContainer()->get(Redactor::class);
    }

    private function written(object $payload): string
    {
        return $this->serializer()->serialize($payload, JsonEncoder::FORMAT);
    }

    private function serializer(): SerializerInterface
    {
        $serializer = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer;
    }

    private function findingAt(string $id, Severity $severity, int $line): Finding
    {
        return new Finding($id, $severity, 'src/A.php', $line, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
