<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Closure;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The comment is owed only while a person's change is the last event of a status label, so a second pass finds Intakt's own event last and stays silent.
#[Exclude]
final readonly class StatusLabelPlan
{
    /**
     * @param list<string> $remove
     */
    private function __construct(
        public RoundStatusLabel $wanted,
        public array $remove,
        public bool $add,
        public bool $commentOnRestore,
    ) {
    }

    /**
     * @param list<LabelEvent>      $events
     * @param Closure(string): bool $isOwn
     */
    public static function of(RoundStatusLabel $wanted, array $events, Closure $isOwn): self
    {
        $last = self::lastEventOfEachStatus($events);
        $changedByPerson = array_filter($last, static fn (LabelEvent $event): bool => !$isOwn($event->actor) && $event->applied !== ($event->label === $wanted->value));
        $present = isset($last[$wanted->value]) && $last[$wanted->value]->applied;
        // A wanted status a person applied is taken off and applied again, because a restore aims at the status Intakt applied last and would otherwise put back an older one.
        $ownPresent = $present && $isOwn($last[$wanted->value]->actor);
        $remove = array_keys(array_filter($last, static fn (LabelEvent $event): bool => $event->applied && ($event->label !== $wanted->value || !$ownPresent)));

        return new self($wanted, $remove, !$ownPresent, [] !== $changedByPerson);
    }

    // Toward the status Intakt applied last, because that is the round as Intakt last mirrored it; null when Intakt never set one, so there is nothing to put back.
    /**
     * @param list<LabelEvent>      $events
     * @param Closure(string): bool $isOwn
     */
    public static function restoring(array $events, Closure $isOwn): ?self
    {
        $own = array_last(array_filter($events, static fn (LabelEvent $event): bool => $event->applied && RoundStatusLabel::tryFrom($event->label) instanceof RoundStatusLabel && $isOwn($event->actor)));

        return $own instanceof LabelEvent ? self::of(RoundStatusLabel::from($own->label), $events, $isOwn) : null;
    }

    /**
     * @param list<LabelEvent> $events
     *
     * @return array<string, LabelEvent>
     */
    private static function lastEventOfEachStatus(array $events): array
    {
        $last = [];
        foreach ($events as $event) {
            if (str_starts_with($event->label, RoundStatusLabel::FAMILY_PREFIX)) {
                $last[$event->label] = $event;
            }
        }

        return $last;
    }
}
