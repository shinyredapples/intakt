<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\ReviewSubject;
use Intakt\Core\Review\Domain\ReviewTarget;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Tests\Core\Review\SampleIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(RoundReviewer::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(ReviewSubject::class)]
#[UsesClass(ReviewTarget::class)]
#[UsesClass(ChangeRound::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(ReviewerState::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelReviewer::class)]
final class RoundReviewerSessionTest extends TestCase
{
    public function testAReviewerHasNoAgentSessionAndNoInterruptionsAtFirst(): void
    {
        $reviewer = $this->reviewer();

        self::assertNull($reviewer->agentSessionId());
        self::assertSame(0, $reviewer->interruptions());
    }

    public function testStartingAgainAfterAnInterruptionKeepsTheAgentSessionId(): void
    {
        $reviewer = $this->reviewer();
        $reviewer->recordAgentSession('session-1');
        $reviewer->recordInterruption();

        $reviewer->recordStart(new DateTimeImmutable('2026-11-20 12:00:00Z'));

        self::assertSame('session-1', $reviewer->agentSessionId());
    }

    public function testARestartAfterAStoppedWorkerClearsTheAgentSessionId(): void
    {
        $reviewer = $this->reviewer();
        $reviewer->recordAgentSession('session-1');

        $reviewer->recordRestart();

        self::assertNull($reviewer->agentSessionId());
    }

    public function testInterruptionsAreCountedAndAreNotRestarts(): void
    {
        $reviewer = $this->reviewer();

        $reviewer->recordInterruption();
        $reviewer->recordInterruption();
        $reviewer->recordInterruption();

        self::assertSame(3, $reviewer->interruptions());
        self::assertFalse($reviewer->hasRestarted());
        self::assertNull($reviewer->failure());
    }

    private function reviewer(): RoundReviewer
    {
        $round = new ReviewRound(Uuid::v7(), new ReviewTarget(new ReviewSubject('alpha', __DIR__, 'Add the thing.', 'diff --git a/a b/a'), SampleIdentity::any()), new ChangeRound('feat/thing', 1, '[]'));
        $round->addReviewer(new UuidV7(), new PanelReviewer(new ReviewerRole('role', 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1));

        return $round->reviewers()[0];
    }
}
