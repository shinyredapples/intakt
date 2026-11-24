<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusLabelPlan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusLabelPlan::class)]
#[UsesClass(LabelEvent::class)]
final class StatusLabelPlanTest extends TestCase
{
    public function testAChangeWithoutAStatusLabelGetsTheWantedOneWithoutAComment(): void
    {
        $plan = StatusLabelPlan::of(RoundStatusLabel::InReview, [new LabelEvent('model:strong', 'alice', true)], $this->own(...));

        self::assertTrue($plan->add);
        self::assertSame([], $plan->remove);
        self::assertFalse($plan->commentOnRestore);
    }

    public function testIntaktsOwnEarlierStatusIsReplacedWithoutAComment(): void
    {
        $plan = StatusLabelPlan::of(RoundStatusLabel::WaitingOnYou, [new LabelEvent('status:in-review', 'intakt[bot]', true)], $this->own(...));

        self::assertSame(['status:in-review'], $plan->remove);
        self::assertTrue($plan->add);
        self::assertFalse($plan->commentOnRestore);
    }

    public function testAStatusLabelAPersonAppliedIsRemovedWithOneComment(): void
    {
        $events = [new LabelEvent('status:in-review', 'intakt[bot]', true), new LabelEvent('status:waiting-on-you', 'alice', true)];

        $plan = StatusLabelPlan::of(RoundStatusLabel::InReview, $events, $this->own(...));

        self::assertSame(['status:waiting-on-you'], $plan->remove);
        self::assertFalse($plan->add);
        self::assertTrue($plan->commentOnRestore);
    }

    public function testTheWantedLabelAPersonRemovedIsPutBackWithOneComment(): void
    {
        $events = [new LabelEvent('status:in-review', 'intakt[bot]', true), new LabelEvent('status:in-review', 'alice', false)];

        $plan = StatusLabelPlan::of(RoundStatusLabel::InReview, $events, $this->own(...));

        self::assertTrue($plan->add);
        self::assertTrue($plan->commentOnRestore);
    }

    public function testASecondPassAfterTheRestoreNeitherWritesNorComments(): void
    {
        $events = [new LabelEvent('status:waiting-on-you', 'alice', true), new LabelEvent('status:waiting-on-you', 'intakt[bot]', false), new LabelEvent('status:in-review', 'intakt[bot]', true)];

        $plan = StatusLabelPlan::of(RoundStatusLabel::InReview, $events, $this->own(...));

        self::assertFalse($plan->add);
        self::assertSame([], $plan->remove);
        self::assertFalse($plan->commentOnRestore);
    }

    public function testARestoreAimsAtTheStatusIntaktAppliedLast(): void
    {
        $events = [new LabelEvent('status:in-review', 'intakt[bot]', true), new LabelEvent('status:waiting-on-you', 'intakt[bot]', true), new LabelEvent('status:waiting-on-you', 'alice', false)];

        $plan = StatusLabelPlan::restoring($events, $this->own(...));

        self::assertSame(RoundStatusLabel::WaitingOnYou, $plan?->wanted);
        self::assertTrue($plan->add);
        self::assertTrue($plan->commentOnRestore);
    }

    public function testARestoreOnAChangeIntaktNeverGaveAStatusPlansNothing(): void
    {
        self::assertNull(StatusLabelPlan::restoring([new LabelEvent('status:in-review', 'alice', true)], $this->own(...)));
    }

    private function own(string $login): bool
    {
        return 'intakt[bot]' === $login;
    }
}
