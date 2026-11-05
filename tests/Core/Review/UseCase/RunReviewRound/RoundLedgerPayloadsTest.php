<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\ShadowRoundHeld;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Infrastructure\ConfiguredProjectModes;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(ReviewStarted::class)]
#[CoversClass(ShadowRoundHeld::class)]
#[CoversClass(ReviewerStarted::class)]
#[CoversClass(VerdictReceived::class)]
#[CoversClass(CatchUpOwed::class)]
#[CoversClass(ReviewerFailed::class)]
#[CoversClass(ReviewerBlocked::class)]
#[CoversClass(ReviewerStopped::class)]
#[CoversClass(JudgeDone::class)]
#[CoversClass(JudgeDoneFinding::class)]
final class RoundLedgerPayloadsTest extends KernelTestCase
{
    private const string ROUND = '0195f0a2-7c1e-7000-8000-000000000001';

    private const string REVIEWER = '0195f0a2-7c1e-7000-8000-000000000002';

    private const string IDENTITY = '{"round":"'.self::ROUND.'","reviewer":"'.self::REVIEWER.'","role":"security","tier":"strong"';

    public function testAStartWithASourceIsWrittenInTodaysFormat(): void
    {
        $started = new ReviewStarted(self::ROUND, 'alpha', 2, 'feat/x', 3, 'T7', '/repos/alpha', 'main');

        self::assertSame('{"round":"'.self::ROUND.'","project":"alpha","reviewers":2,"change":"feat\/x","number":3,"task":"T7","repository":"\/repos\/alpha","base":"main"}', $this->written($started));
    }

    public function testAStartWithoutASourceHasNoRepositoryOrBaseKey(): void
    {
        $started = new ReviewStarted(self::ROUND, 'alpha', 1, 'feat/x', 1, 'T7');

        self::assertSame('{"round":"'.self::ROUND.'","project":"alpha","reviewers":1,"change":"feat\/x","number":1,"task":"T7"}', $this->written($started));
    }

    public function testAHeldShadowRoundIsWrittenWithTodaysProviderValue(): void
    {
        $held = new ShadowRoundHeld('alpha', 'feat/x', Provider::Anthropic, 'at-ceiling');

        self::assertSame('{"project":"alpha","change":"feat\/x","provider":"anthropic","reason":"at-ceiling"}', $this->written($held));
    }

    public function testAStartedReviewerIsWrittenWithItsIdentity(): void
    {
        self::assertSame(self::IDENTITY.'}', $this->written(new ReviewerStarted(self::ROUND, self::REVIEWER, 'security', Tier::Strong)));
    }

    public function testAReceivedVerdictIsWrittenWithTodaysDecisionAndModeValues(): void
    {
        $received = new VerdictReceived(self::ROUND, self::REVIEWER, 'security', Tier::Strong, Decision::Changes, 2, ProjectMode::Shadow);

        self::assertSame(self::IDENTITY.',"decision":"changes","findings":2,"mode":"shadow"}', $this->written($received));
    }

    public function testAnOwedCatchUpIsWrittenWithItsSubstitute(): void
    {
        $owed = new CatchUpOwed(self::ROUND, self::REVIEWER, 'security', Tier::Strong, Provider::OpenAi);

        self::assertSame(self::IDENTITY.',"substitute":"openai"}', $this->written($owed));
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
        $done = new JudgeDone(self::ROUND, ['f1'], ['f2'], [new JudgeDoneFinding('f1', Severity::P1, 'A bug.'), new JudgeDoneFinding('f2', Severity::P3, 'A nit.')]);

        self::assertSame('{"round":"'.self::ROUND.'","act":["f1"],"follow_ups":["f2"],"findings":[{"id":"f1","severity":"P1","text":"A bug."},{"id":"f2","severity":"P3","text":"A nit."}]}', $this->written($done));
    }

    public function testADoneJudgeWithoutFollowUpsStillWritesTheKey(): void
    {
        self::assertSame('{"round":"'.self::ROUND.'","act":[],"follow_ups":[],"findings":[]}', $this->written(new JudgeDone(self::ROUND, [], [], [])));
    }

    public function testTheLedgerSpreadsTheReviewerIdentityBeforeTheEventsOwnKeys(): void
    {
        $reviewer = new RoundFixture(self::getContainer())->start(1)->reviewers()[0];
        $serializer = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $serializer);
        $ledger = new InMemoryLedger();

        new RoundLedger($ledger, new ConfiguredProjectModes([]), $serializer)->reviewerFailed($reviewer, 'RuntimeException');

        $payload = $ledger->payloads()[0];
        self::assertSame(['round', 'reviewer', 'role', 'tier', 'failure'], array_keys($payload));
        self::assertSame([$reviewer->round()->id()->toRfc4122(), $reviewer->id()->toRfc4122()], [$payload['round'], $payload['reviewer']]);
    }

    private function written(object $payload): string
    {
        $serializer = self::getContainer()->get('serializer.stored');
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return $serializer->serialize($payload, 'json');
    }
}
