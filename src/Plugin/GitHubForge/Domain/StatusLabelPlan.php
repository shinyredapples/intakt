<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

use Closure;
use Intakt\Plugin\Forge\Contract\RoundStatusLabel;

// What to write so exactly one status label stands. The comment is owed only while a person's change is still on the change, so a second pass finds Intakt's own label last and stays silent.
final readonly class StatusLabelPlan
{
    /**
     * @param list<string> $remove
     */
    private function __construct(
        public array $remove,
        public bool $add,
        public bool $commentOnRestore,
    ) {
    }

    /**
     * @param list<LabelEvent>      $events earliest first
     * @param Closure(string): bool $isOwn  whether an account is Intakt's own identity
     */
    public static function of(RoundStatusLabel $wanted, array $events, Closure $isOwn): self
    {
        $last = [];
        foreach ($events as $event) {
            if (str_starts_with($event->label, RoundStatusLabel::FAMILY_PREFIX)) {
                $last[$event->label] = $event;
            }
        }

        $remove = [];
        $byPerson = false;
        foreach ($last as $label => $event) {
            $personsChange = !$isOwn($event->actor);
            if ($event->applied && $label !== $wanted->value) {
                $remove[] = $label;
                $byPerson = $byPerson || $personsChange;
            }
            if (!$event->applied && $label === $wanted->value && $personsChange) {
                $byPerson = true;
            }
        }

        $present = isset($last[$wanted->value]) && $last[$wanted->value]->applied;

        return new self($remove, !$present, $byPerson);
    }
}
