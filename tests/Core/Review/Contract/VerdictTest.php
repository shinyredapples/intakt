<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictOrigin;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Verdict::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Evidence::class)]
#[UsesClass(VerdictOrigin::class)]
final class VerdictTest extends TestCase
{
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

    public function testAReviewersBlockedVerdictWithFindingsIsRecordedAsChanges(): void
    {
        $verdict = new Verdict(Decision::Blocked, [$this->finding()], ['F0'], ['a follow-up']);

        $recorded = $verdict->asRecorded();

        self::assertSame(Decision::Changes, $recorded->verdict);
        self::assertEquals([$this->finding()], $recorded->findings);
        self::assertSame(['F0'], $recorded->resolved);
        self::assertSame(['a follow-up'], $recorded->follow_ups);
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

    #[DataProvider('failureIds')]
    public function testAReviewersBlockedAnswerNamingAFailureIdIsStillRecordedAsChanges(string $failureId): void
    {
        $answer = VerdictDocument::fromTheDesign()
            ->with('verdict', 'blocked')
            ->with('resolved', [])
            ->withFinding('id', $failureId)
            ->withFinding('file', 'src/Thing.php');
        $verdict = StoredSerializerFactory::create()->deserialize($answer->json(), Verdict::class, 'json');

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
        $verdict = StoredSerializerFactory::create()->deserialize($answer->json(), Verdict::class, 'json');

        self::assertSame([$failureId], $verdict->claimedFailureIds());
    }

    public function testAReviewersAnswerWithOrdinaryIdsClaimsNoFailureId(): void
    {
        self::assertSame([], new Verdict(Decision::Changes, [$this->finding()], [], [])->claimedFailureIds());
    }

    #[DataProvider('failureVerdicts')]
    public function testAnIntaktFailureVerdictClaimsNoFailureId(Verdict $failure): void
    {
        self::assertSame([], $failure->claimedFailureIds());
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
