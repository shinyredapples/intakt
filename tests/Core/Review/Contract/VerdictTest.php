<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FindingKind;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUpNote;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictOrigin;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Validator\Validation;

#[CoversClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(VerdictOrigin::class)]
#[UsesClass(FindingIds::class)]
final class VerdictTest extends TestCase
{
    public function testAFailureVerdictIsIntaktsAndAReviewersAnswerIsNot(): void
    {
        self::assertTrue(Verdict::noTools()->isIntakts());
        self::assertFalse(new Verdict(Decision::Blocked, [], [], [])->isIntakts());
    }

    public function testAnIncompleteStreamVerdictBlocksAndNamesTheReason(): void
    {
        $verdict = Verdict::incompleteStream('the stream ended early', new Evidence('the call to anthropic, model big', 'cut by: platform'));

        self::assertSame(Decision::Blocked, $verdict->verdict);
        self::assertSame(Verdict::INCOMPLETE_STREAM, $verdict->findings[0]->id);
        self::assertStringContainsString('the stream ended early', $verdict->findings[0]->text);
    }

    public function testAnIncompleteStreamVerdictKeepsTheEvidenceOfTheCut(): void
    {
        $evidence = new Evidence('the call to anthropic, model big', "cut by: platform\nchunks: 2");

        self::assertSame($evidence, Verdict::incompleteStream('the stream ended early', $evidence)->findings[0]->evidence);
    }

    public function testAnApprovalAndChangesOfOnlyP3FindingsMayCountAsApproval(): void
    {
        self::assertSame([true, true], [
            new Verdict(Decision::Approve, [], [], [])->mayCountAsApproval(),
            $this->changes(Severity::P3, Severity::P3)->mayCountAsApproval(),
        ]);
    }

    public function testChangesWithAP2WithoutFindingsOrOfAFailedReviewNeverCountAsApproval(): void
    {
        $failed = new Verdict(Decision::Changes, [new Finding(Verdict::NO_TOOLS, Severity::P3, null, null, null, 'No tools.', FixableBy::Owner, false, null, null)], [], []);

        self::assertSame([false, false, false, false], [
            $this->changes(Severity::P3, Severity::P2)->mayCountAsApproval(),
            $this->changes()->mayCountAsApproval(),
            $failed->mayCountAsApproval(),
            Verdict::unreadApproval()->mayCountAsApproval(),
        ]);
    }

    public function testAReviewersBlockedVerdictOfOnlyP3FindingsMayCountAsApprovalBecauseItIsRecordedAsChanges(): void
    {
        $blocked = new Verdict(Decision::Blocked, [new Finding('A', Severity::P3, null, null, null, 'Text.', FixableBy::Builder, false, null, null)], [], []);
        $withAP2 = new Verdict(Decision::Blocked, [new Finding('A', Severity::P2, null, null, null, 'Text.', FixableBy::Builder, false, null, null)], [], []);

        self::assertSame([true, false], [$blocked->mayCountAsApproval(), $withAP2->mayCountAsApproval()]);
    }

    public function testAReviewersBlockedVerdictWithFindingsIsRecordedAsChanges(): void
    {
        $verdict = new Verdict(Decision::Blocked, [$this->finding()], [new ResolvedFinding('F0')], [new FollowUpNote('a follow-up')]);

        $recorded = $verdict->asRecorded();

        self::assertSame(Decision::Changes, $recorded->verdict);
        self::assertEquals([$this->finding()], $recorded->findings);
        self::assertEquals([new ResolvedFinding('F0')], $recorded->resolved);
        self::assertEquals([new FollowUpNote('a follow-up')], $recorded->follow_ups);
    }

    public function testABlockedVerdictWithoutFindingsStaysBlocked(): void
    {
        $verdict = new Verdict(Decision::Blocked, [], [], []);

        self::assertSame(Decision::Blocked, $verdict->asRecorded()->verdict);
    }

    /**
     * @return iterable<string, array{Verdict}>
     */
    public static function failureVerdicts(): iterable
    {
        yield 'no tools' => [Verdict::noTools()];
        yield 'stopped reviewer' => [Verdict::reviewerStopped()];
        yield 'cut approval' => [Verdict::cutApproval()];
        yield 'unread approval' => [Verdict::unreadApproval()];
        yield 'incomplete stream' => [Verdict::incompleteStream('cut', new Evidence('the call', 'cut by: platform'))];
    }

    #[DataProvider('failureVerdicts')]
    public function testAnIntaktFailureVerdictStaysBlocked(Verdict $failure): void
    {
        self::assertSame(Decision::Blocked, $failure->asRecorded()->verdict);
    }

    #[DataProvider('failureVerdicts')]
    public function testAnIntaktFailureFindingHasATitleOfThreeToSixWordsAndTheKindOther(Verdict $failure): void
    {
        $finding = $failure->findings[0];
        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validateProperty($finding, 'title');

        self::assertSame([0, FindingKind::Other], [count($violations), $finding->kind]);
    }

    #[DataProvider('failureIds')]
    public function testAReviewersBlockedAnswerNamingAFailureIdIsStillRecordedAsChanges(string $failureId): void
    {
        $answer = VerdictDocument::fromTheDesign()
            ->with('verdict', 'blocked')
            ->with('resolved', [])
            ->withFinding('id', $failureId)
            ->withFinding('file', 'src/Thing.php');
        $verdict = StoredSerializerFactory::create()->deserialize($answer->json(), ReviewerAnswer::class, JsonEncoder::FORMAT)->toVerdict();

        self::assertSame(Decision::Changes, $verdict->asRecorded()->verdict);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failureIds(): iterable
    {
        yield 'no tools' => [Verdict::NO_TOOLS];
        yield 'stopped reviewer' => [Verdict::REVIEWER_STOPPED];
        yield 'invalid output' => [Verdict::INVALID_OUTPUT];
    }

    #[DataProvider('failureIds')]
    public function testAReviewersAnswerNamingAFailureIdClaimsThatId(string $failureId): void
    {
        $answer = VerdictDocument::fromTheDesign()
            ->with('verdict', 'changes')
            ->with('resolved', [])
            ->withFinding('id', $failureId);
        $verdict = StoredSerializerFactory::create()->deserialize($answer->json(), ReviewerAnswer::class, JsonEncoder::FORMAT)->toVerdict();

        self::assertSame([$failureId], [...$verdict->claimedFailureIds()]);
    }

    public function testAReviewersAnswerWithOrdinaryIdsClaimsNoFailureId(): void
    {
        self::assertSame([], [...new Verdict(Decision::Changes, [$this->finding()], [], [])->claimedFailureIds()]);
    }

    #[DataProvider('failureVerdicts')]
    public function testAnIntaktFailureVerdictClaimsNoFailureId(Verdict $failure): void
    {
        self::assertSame([], [...$failure->claimedFailureIds()]);
    }

    public function testAFailureVerdictWithItsFindingsReplacedStaysBlocked(): void
    {
        self::assertSame(Decision::Blocked, Verdict::noTools()->withFindings([$this->finding()])->asRecorded()->verdict);
    }

    public function testABlockedVerdictIntaktGaveNamesItsFindingAsItsReason(): void
    {
        self::assertSame(Verdict::noTools()->findings[0]->text, Verdict::noTools()->blockedReason());
    }

    public function testAVerdictThatIsNotBlockedHasNoBlockedReason(): void
    {
        self::assertNull(new Verdict(Decision::Changes, [$this->finding()], [], [])->blockedReason());
    }

    public function testABlockedVerdictWithoutAFindingHasNoBlockedReason(): void
    {
        self::assertNull(new Verdict(Decision::Blocked, [], [], [])->blockedReason());
    }

    public function testAnApprovalAndAChangesVerdictAreRecordedAsGiven(): void
    {
        self::assertSame(Decision::Approve, new Verdict(Decision::Approve, [], [], [])->asRecorded()->verdict);
        self::assertSame(Decision::Changes, new Verdict(Decision::Changes, [$this->finding()], [], [])->asRecorded()->verdict);
    }

    private function changes(Severity ...$severities): Verdict
    {
        return new Verdict(Decision::Changes, array_map(static fn (Severity $severity): Finding => new Finding('A', $severity, null, null, null, 'Text.', FixableBy::Builder, false, null, null), array_values($severities)), [], []);
    }

    private function finding(): Finding
    {
        return new Finding(
            id: 'F1',
            severity: Severity::P1,
            file: 'src/Cart.php',
            line: 12,
            symbol: null,
            text: 'The total adds the discount.',
            fixable_by: FixableBy::Builder,
            repeat: false,
            requirement: 'Storytelling',
            evidence: null,
        );
    }
}
