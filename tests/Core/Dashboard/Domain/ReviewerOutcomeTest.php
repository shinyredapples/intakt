<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\AiLab;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewerOutcome::class)]
#[CoversClass(ReviewOutcome::class)]
#[UsesClass(AiLab::class)]
final class ReviewerOutcomeTest extends TestCase
{
    /**
     * @return iterable<string, array{Decision, ReviewOutcome}>
     */
    public static function decisions(): iterable
    {
        yield 'approve' => [Decision::Approve, ReviewOutcome::Approved];
        yield 'changes' => [Decision::Changes, ReviewOutcome::ChangesRequested];
        yield 'blocked' => [Decision::Blocked, ReviewOutcome::Blocked];
    }

    #[DataProvider('decisions')]
    public function testAReviewerThatAnsweredGivesItsDecisionInTheOutcomeWords(Decision $decision, ReviewOutcome $outcome): void
    {
        self::assertSame($outcome, $this->reviewer()->answered($decision, 2, ProjectMode::Live)->outcome());
    }

    public function testAnAnsweredReviewerKeepsItsDecisionAsADecision(): void
    {
        self::assertSame(Decision::Changes, $this->reviewer()->answered(Decision::Changes, 2, ProjectMode::Live)->decision);
    }

    public function testAReviewerStartedWithAnEmptyModelNamesItsTierLikeOneWithNone(): void
    {
        $reviewer = new ReviewerOutcome('r1', 'correctness', 'standard', new DateTimeImmutable('2026-11-13 10:00:00'), '');

        self::assertSame('standard tier, model not recorded', $reviewer->modelInWords());
    }

    public function testAReviewerOnAModelOfAKnownAiLabIsShownAsModelAndAiLab(): void
    {
        $reviewer = new ReviewerOutcome('r1', 'correctness', 'standard', new DateTimeImmutable('2026-11-13 10:00:00'), 'moonshotai/Kimi-K3.1:deepinfra');

        self::assertSame('Kimi K3.1 (Moonshot AI)', $reviewer->modelAndLab());
    }

    public function testAReviewerOnAModelOfAnUnknownAiLabIsShownByItsIdAlone(): void
    {
        $reviewer = new ReviewerOutcome('r1', 'correctness', 'standard', new DateTimeImmutable('2026-11-13 10:00:00'), 'acme/robot-7');

        self::assertSame('acme/robot-7', $reviewer->modelAndLab());
    }

    public function testAReviewerOnANameThatOnlyStartsLikeAKnownFamilyIsShownByItsIdAlone(): void
    {
        $reviewer = new ReviewerOutcome('r1', 'correctness', 'standard', new DateTimeImmutable('2026-11-13 10:00:00'), 'acme/gptish');

        self::assertSame('acme/gptish', $reviewer->modelAndLab());
    }

    public function testAReviewerOnABareLabNamespaceIsShownByItsIdAlone(): void
    {
        $reviewer = new ReviewerOutcome('r1', 'correctness', 'standard', new DateTimeImmutable('2026-11-13 10:00:00'), 'anthropic/');

        self::assertSame('anthropic/', $reviewer->modelAndLab());
    }

    public function testAReviewerWhoseModelWasNotRecordedIsShownByItsTier(): void
    {
        self::assertSame('standard tier, model not recorded', $this->reviewer()->modelAndLab());
    }

    public function testAFailedReviewerGivesFailed(): void
    {
        self::assertSame(ReviewOutcome::Failed, $this->reviewer()->failed('timeout')->outcome());
    }

    public function testABlockedReviewerGivesBlocked(): void
    {
        self::assertSame(ReviewOutcome::Blocked, $this->reviewer()->blocked('no access')->outcome());
    }

    public function testARunningReviewerGivesNoOutcome(): void
    {
        self::assertNull($this->reviewer()->outcome());
    }

    public function testAStoppedReviewerGivesNoOutcome(): void
    {
        self::assertNull($this->reviewer()->stopped()->outcome());
    }

    public function testAnInterruptedReviewerKeepsItsInterruptionCountAndGivesNoOutcome(): void
    {
        $interrupted = $this->reviewer()->interrupted(3);

        self::assertSame([3, null], [$interrupted->interruptions, $interrupted->outcome()]);
    }

    public function testAReviewerStartedAgainAfterAnAnswerGivesNoOutcome(): void
    {
        $restarted = $this->reviewer()->answered(Decision::Approve, 0, ProjectMode::Live)->restarted(new DateTimeImmutable('2026-10-28T11:05:00+00:00'), null);

        self::assertNull($restarted->outcome());
    }

    private function reviewer(): ReviewerOutcome
    {
        return new ReviewerOutcome('a', 'Code reviewer', 'standard', new DateTimeImmutable('2026-10-28T11:01:00+00:00'));
    }
}
