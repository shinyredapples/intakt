<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Closure;
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
        private ?RoundStatusLabel $wanted,
        public array $remove,
        public ?RoundStatusLabel $add,
    ) {
    }

    /**
     * @param list<string> $present
     */
    public static function toward(?RoundStatusLabel $wanted, array $present): self
    {
        $statuses = array_values(array_filter($present, self::isStatus(...)));
        $remove = array_values(array_filter($statuses, static fn (string $label): bool => $label !== $wanted?->value));
        $add = $wanted instanceof RoundStatusLabel && !in_array($wanted->value, $statuses, true) ? $wanted : null;

        return new self($wanted, $remove, $add);
    }

    public function changesLabels(): bool
    {
        return [] !== $this->remove || $this->add instanceof RoundStatusLabel;
    }

    // Owed when the latest event on a status label is a person's that the round disagrees with, because only then is a person's edit what this change undoes.
    /**
     * @param list<LabelEvent>      $events
     * @param Closure(string): bool $isOwn
     */
    public function owesComment(array $events, Closure $isOwn): bool
    {
        $latest = array_last(array_filter($events, static fn (LabelEvent $event): bool => self::isStatus($event->label)));

        return $this->changesLabels() && $latest instanceof LabelEvent && !$isOwn($latest->actor) && $this->disagreesWith($latest);
    }

    private static function isStatus(string $label): bool
    {
        return str_starts_with($label, RoundStatusLabel::FAMILY_PREFIX);
    }

    // Applying the round's own status, or removing another one, is what the round wants too.
    private function disagreesWith(LabelEvent $event): bool
    {
        return $event->applied !== ($event->label === $this->wanted?->value);
    }
}
