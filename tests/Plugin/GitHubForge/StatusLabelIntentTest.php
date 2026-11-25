<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusLabelIntent;
use Intakt\Plugin\GitHubForge\Domain\StatusLabelPlan;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusLabelIntent::class)]
#[UsesClass(StatusLabelPlan::class)]
#[UsesClass(LabelEvent::class)]
final class StatusLabelIntentTest extends TestCase
{
    private const string OWN = 'intakt[bot]';
    private const string PERSON = 'someone';

    public function testAMirrorPlansItsStatusWithTheComment(): void
    {
        $plan = StatusLabelIntent::mirroring(RoundStatusLabel::InReview, $this->isOwn())->plan([new LabelEvent('status:waiting-on-you', self::PERSON, true)]);

        self::assertNotNull($plan);
        self::assertTrue($plan->commentOnRestore);
    }

    public function testAMirrorWithoutTheCommentPlansNoComment(): void
    {
        $plan = StatusLabelIntent::mirroring(RoundStatusLabel::InReview, $this->isOwn())->withoutComment()->plan([new LabelEvent('status:waiting-on-you', self::PERSON, true)]);

        self::assertNotNull($plan);
        self::assertFalse($plan->commentOnRestore);
    }

    public function testAWriteWhoseOwnEventsAreTheOnlyNewOnesNeedsNoSettling(): void
    {
        $before = [new LabelEvent('status:in-review', self::OWN, true)];
        $intent = StatusLabelIntent::mirroring(RoundStatusLabel::WaitingOnYou, $this->isOwn());
        $written = $this->planned($intent, $before);

        self::assertNull($intent->settling($written, [...$before, new LabelEvent('status:in-review', self::OWN, false), new LabelEvent('status:waiting-on-you', self::OWN, true)], 1));
    }

    public function testAPersonsEventAfterTheWriteNeedsNoSettling(): void
    {
        $intent = StatusLabelIntent::mirroring(RoundStatusLabel::WaitingOnYou, $this->isOwn());
        $written = $this->planned($intent, []);

        self::assertNull($intent->settling($written, [new LabelEvent('status:waiting-on-you', self::OWN, true), new LabelEvent('status:in-review', self::PERSON, true)], 0));
    }

    public function testAMirrorWritesItsStatusAgainOverAnotherWritersStatus(): void
    {
        $intent = StatusLabelIntent::mirroring(RoundStatusLabel::WaitingOnYou, $this->isOwn());
        $written = $this->planned($intent, []);

        $settled = $intent->settling($written, [new LabelEvent('status:in-review', self::OWN, true), new LabelEvent('status:waiting-on-you', self::OWN, true)], 0);

        self::assertNotNull($settled);
        self::assertSame([RoundStatusLabel::WaitingOnYou, ['status:in-review'], false, false], [$settled->wanted, $settled->remove, $settled->add, $settled->commentOnRestore]);
    }

    public function testARestorationGivesWayToTheStatusAnotherWriterApplied(): void
    {
        $before = [new LabelEvent('status:in-review', self::OWN, true), new LabelEvent('status:in-review', self::PERSON, false)];
        $intent = StatusLabelIntent::restoring($this->isOwn());
        $written = $this->planned($intent, $before);

        $settled = $intent->settling($written, [...$before, new LabelEvent('status:waiting-on-you', self::OWN, true), new LabelEvent('status:in-review', self::OWN, true)], 2);

        self::assertNotNull($settled);
        self::assertSame([RoundStatusLabel::WaitingOnYou, ['status:in-review'], false, false], [$settled->wanted, $settled->remove, $settled->add, $settled->commentOnRestore]);
    }

    public function testARestorationKeepsItsStatusWhenTheOtherWriterOnlyRemovedOne(): void
    {
        $before = [new LabelEvent('status:in-review', self::OWN, true), new LabelEvent('status:in-review', self::PERSON, false)];
        $intent = StatusLabelIntent::restoring($this->isOwn());
        $written = $this->planned($intent, $before);

        $settled = $intent->settling($written, [...$before, new LabelEvent('status:in-review', self::OWN, true), new LabelEvent('status:waiting-on-you', self::OWN, false)], 2);

        self::assertNotNull($settled);
        self::assertSame([RoundStatusLabel::InReview, [], false], [$settled->wanted, $settled->remove, $settled->add]);
    }

    /**
     * @param list<LabelEvent> $events
     */
    private function planned(StatusLabelIntent $intent, array $events): StatusLabelPlan
    {
        return $intent->plan($events) ?? throw new LogicException('No plan.');
    }

    /**
     * @return Closure(string): bool
     */
    private function isOwn(): Closure
    {
        return static fn (string $login): bool => self::OWN === $login;
    }
}
