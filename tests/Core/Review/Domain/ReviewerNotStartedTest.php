<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Domain;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Domain\ChangeRound;
use Intakt\Core\Review\Domain\ReviewerNotStarted;
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

#[CoversClass(ReviewerNotStarted::class)]
#[UsesClass(RoundReviewer::class)]
#[UsesClass(ReviewRound::class)]
#[UsesClass(ReviewSubject::class)]
#[UsesClass(ReviewTarget::class)]
#[UsesClass(ChangeRound::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(PanelReviewer::class)]
final class ReviewerNotStartedTest extends TestCase
{
    public function testAReviewerThatWasNeverStartedHasNoConversation(): void
    {
        $this->expectException(ReviewerNotStarted::class);

        $this->reviewer()->conversationId();
    }

    private function reviewer(): RoundReviewer
    {
        $round = new ReviewRound(Uuid::v7(), new ReviewTarget(new ReviewSubject('alpha', __DIR__, 'Add the thing.'), SampleIdentity::any()), new ChangeRound('feat/thing', 1, '[]'));
        $round->addReviewer(new UuidV7(), new PanelReviewer(new ReviewerRole('role', 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1));

        return $round->reviewers()[0];
    }
}
