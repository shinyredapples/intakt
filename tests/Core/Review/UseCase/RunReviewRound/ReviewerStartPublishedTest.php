<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\UseCase\RunReviewRound\LiveRoundProgress;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(LiveRoundProgress::class)]
final class ReviewerStartPublishedTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testAStartedReviewerIsPublishedOnce(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];

        $this->fixture->deliver($message, new ScriptedVendor());

        self::assertSame([$message->reviewerId], $this->fixture->updates->started);
    }

    public function testAMessageForAReviewerThatAlreadyFinishedPublishesNoStart(): void
    {
        $this->fixture->start(1);
        $message = $this->fixture->bus->messages[0];
        $this->fixture->deliver($message, new ScriptedVendor());

        $this->fixture->deliver($message, new ScriptedVendor());

        self::assertSame([$message->reviewerId], $this->fixture->updates->started);
    }
}
