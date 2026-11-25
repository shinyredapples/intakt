<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Closure;
use Intakt\Core\Review\Contract\ReviewStatus;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The status comes from the stored round alone, and the events only say whether the change undoes a person's edit, so a second pass that finds the labels right writes and says nothing.
#[Exclude]
final readonly class StatusReconciliation
{
    /**
     * @param list<string> $remove
     */
    private function __construct(
        public string $wanted,
        public array $remove,
        public ?string $add,
    ) {
    }

    /**
     * @param list<string> $present
     */
    public static function toward(ReviewStatus $status, array $present): self
    {
        $wanted = RoundStatusLabel::of($status);
        $statuses = array_values(array_filter($present, RoundStatusLabel::isStatus(...)));
        $remove = array_values(array_filter($statuses, static fn (string $label): bool => $label !== $wanted));

        return new self($wanted, $remove, in_array($wanted, $statuses, true) ? null : $wanted);
    }

    public function changesLabels(): bool
    {
        return [] !== $this->remove || null !== $this->add;
    }

    // Owed only when the latest status event is a person's that disagrees with the round, because only then does this change undo what a person did (maintainer decision 3 of #3900).
    /**
     * @param list<LabelEvent>      $events
     * @param Closure(string): bool $isOwn
     */
    public function owesComment(array $events, Closure $isOwn): bool
    {
        $latest = array_last(array_filter($events, static fn (LabelEvent $event): bool => RoundStatusLabel::isStatus($event->label)));

        return $this->changesLabels()
            && $latest instanceof LabelEvent
            && $this->disagrees($latest)
            && !$isOwn($latest->actor);
    }

    private function disagrees(LabelEvent $event): bool
    {
        return $event->applied !== ($event->label === $this->wanted);
    }
}
