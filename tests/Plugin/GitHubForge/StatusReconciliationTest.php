<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Closure;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Intakt\Plugin\GitHubForge\Domain\LabelEvent;
use Intakt\Plugin\GitHubForge\Domain\StatusReconciliation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusReconciliation::class)]
#[UsesClass(LabelEvent::class)]
final class StatusReconciliationTest extends TestCase
{
    private const string OWN = 'intakt[bot]';
    private const string PERSON = 'alice';

    public function testTheStatusOfTheRoundIsAddedWhenMissing(): void
    {
        $reconciliation = StatusReconciliation::toward(RoundStatusLabel::InReview, ['model:strong']);

        self::assertSame([[], RoundStatusLabel::InReview], [$reconciliation->remove, $reconciliation->add]);
    }

    public function testAStaleStatusLabelIsReplacedByTheStatusOfTheRound(): void
    {
        $reconciliation = StatusReconciliation::toward(RoundStatusLabel::WaitingOnYou, ['status:in-review', 'review:full']);

        self::assertSame([['status:in-review'], RoundStatusLabel::WaitingOnYou], [$reconciliation->remove, $reconciliation->add]);
    }

    public function testEveryOtherStatusLabelIsRemoved(): void
    {
        $reconciliation = StatusReconciliation::toward(RoundStatusLabel::WaitingOnYou, ['status:in-review', 'status:waiting-on-you', 'status:blocked']);

        self::assertSame([['status:in-review', 'status:blocked'], null], [$reconciliation->remove, $reconciliation->add]);
    }

    public function testNoRoundLeavesNoStatusLabel(): void
    {
        $reconciliation = StatusReconciliation::toward(null, ['status:in-review', 'review:full']);

        self::assertSame([['status:in-review'], null], [$reconciliation->remove, $reconciliation->add]);
    }

    public function testLabelsThatMatchTheRoundNeedNoChange(): void
    {
        self::assertFalse(StatusReconciliation::toward(RoundStatusLabel::InReview, ['status:in-review', 'model:fast'])->changesLabels());
    }

    public function testUndoingAPersonsAppliedLabelOwesAComment(): void
    {
        $events = [new LabelEvent('status:in-review', self::OWN, true), new LabelEvent('status:waiting-on-you', self::PERSON, true)];

        self::assertTrue(StatusReconciliation::toward(RoundStatusLabel::InReview, ['status:in-review', 'status:waiting-on-you'])->owesComment($events, $this->isOwn()));
    }

    public function testUndoingAPersonsRemovalOfTheRoundsStatusOwesAComment(): void
    {
        $events = [new LabelEvent('status:waiting-on-you', self::OWN, true), new LabelEvent('status:waiting-on-you', self::PERSON, false)];

        self::assertTrue(StatusReconciliation::toward(RoundStatusLabel::WaitingOnYou, [])->owesComment($events, $this->isOwn()));
    }

    public function testFollowingTheRoundAfterIntaktsOwnWriteOwesNoComment(): void
    {
        $events = [new LabelEvent('status:in-review', self::OWN, true)];

        self::assertFalse(StatusReconciliation::toward(RoundStatusLabel::WaitingOnYou, ['status:in-review'])->owesComment($events, $this->isOwn()));
    }

    public function testAPersonWhoAppliedTheRoundsStatusIsOwedNoCommentThoughTheOtherStatusLabelGoes(): void
    {
        $events = [new LabelEvent('status:in-review', self::OWN, true), new LabelEvent('status:waiting-on-you', self::PERSON, true)];
        $reconciliation = StatusReconciliation::toward(RoundStatusLabel::WaitingOnYou, ['status:in-review', 'status:waiting-on-you']);

        self::assertSame([true, false], [$reconciliation->changesLabels(), $reconciliation->owesComment($events, $this->isOwn())]);
    }

    public function testAPersonWhoRemovedAStaleStatusIsOwedNoComment(): void
    {
        $events = [new LabelEvent('status:in-review', self::PERSON, false)];

        self::assertFalse(StatusReconciliation::toward(RoundStatusLabel::WaitingOnYou, [])->owesComment($events, $this->isOwn()));
    }

    public function testAPersonsStatusOnAChangeWithoutARoundOwesAComment(): void
    {
        $events = [new LabelEvent('status:in-review', self::PERSON, true)];

        self::assertTrue(StatusReconciliation::toward(null, ['status:in-review'])->owesComment($events, $this->isOwn()));
    }

    public function testLabelsAlreadyRightOweNoCommentThoughAPersonEditedLast(): void
    {
        $events = [new LabelEvent('status:in-review', self::OWN, false), new LabelEvent('status:in-review', self::PERSON, true)];

        self::assertFalse(StatusReconciliation::toward(RoundStatusLabel::WaitingOnYou, ['status:waiting-on-you'])->owesComment($events, $this->isOwn()));
    }

    public function testEventsOnOtherLabelsAreNotWhoChangedTheStatus(): void
    {
        $events = [new LabelEvent('status:in-review', self::OWN, true), new LabelEvent('review:full', self::PERSON, true)];

        self::assertFalse(StatusReconciliation::toward(RoundStatusLabel::WaitingOnYou, ['status:in-review', 'review:full'])->owesComment($events, $this->isOwn()));
    }

    /**
     * @return Closure(string): bool
     */
    private function isOwn(): Closure
    {
        return static fn (string $login): bool => self::OWN === $login;
    }
}
