<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Closure;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What one writer means to leave on the change, so its plan and its answer to another writer's status come from one place.
#[Exclude]
final readonly class StatusLabelIntent
{
    /**
     * @param Closure(string): bool $isOwn
     */
    private function __construct(
        private ?RoundStatusLabel $mirrored,
        private Closure $isOwn,
        private bool $comments,
    ) {
    }

    /**
     * @param Closure(string): bool $isOwn
     */
    public static function mirroring(RoundStatusLabel $status, Closure $isOwn): self
    {
        return new self($status, $isOwn, true);
    }

    /**
     * @param Closure(string): bool $isOwn
     */
    public static function restoring(Closure $isOwn): self
    {
        return new self(null, $isOwn, true);
    }

    // For a write made without the lock, because the writer holding it comments on the person's change already.
    public function withoutComment(): self
    {
        return clone ($this, ['comments' => false]);
    }

    /**
     * @param list<LabelEvent> $events
     */
    public function plan(array $events): ?StatusLabelPlan
    {
        $plan = $this->mirrored instanceof RoundStatusLabel ? StatusLabelPlan::of($this->mirrored, $events, $this->isOwn) : StatusLabelPlan::restoring($events, $this->isOwn);

        return $this->comments ? $plan : $plan?->withoutComment();
    }

    // Null unless Intakt's own events since the read hold one this write did not make: a writer past the wait landed between that read and the write. A mirror then writes its status again, because it states the round as it is now; a restoration gives way to the other writer's status, because it only puts back an older one.
    /**
     * @param list<LabelEvent> $after
     */
    public function settling(StatusLabelPlan $written, array $after, int $read): ?StatusLabelPlan
    {
        $others = $this->othersWrites(array_slice($after, $read), $written);
        if ([] === $others) {
            return null;
        }

        $set = array_last(array_filter($others, static fn (LabelEvent $event): bool => $event->applied && RoundStatusLabel::tryFrom($event->label) instanceof RoundStatusLabel));
        $toward = $this->mirrored ?? ($set instanceof LabelEvent ? RoundStatusLabel::from($set->label) : $written->wanted);

        return StatusLabelPlan::of($toward, $after, $this->isOwn)->withoutComment();
    }

    // Matched one for one, so a second event of the same label still stands out.
    /**
     * @param list<LabelEvent> $since
     *
     * @return list<LabelEvent>
     */
    private function othersWrites(array $since, StatusLabelPlan $written): array
    {
        $mine = array_map(static fn (string $label): array => [$label, false], $written->remove);
        if ($written->add) {
            $mine[] = [$written->wanted->value, true];
        }

        $others = [];
        foreach (array_filter($since, fn (LabelEvent $event): bool => ($this->isOwn)($event->actor)) as $event) {
            $match = array_search([$event->label, $event->applied], $mine, true);
            if (false === $match) {
                $others[] = $event;
            } else {
                unset($mine[$match]);
            }
        }

        return $others;
    }
}
