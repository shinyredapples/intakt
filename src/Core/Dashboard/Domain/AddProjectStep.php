<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The Add project flow's steps in their order, so the progress, the step count and the Back and Continue links read one list (mock add-project.html); its steps differ from the setup wizard's, so it is a list of its own.
enum AddProjectStep: int
{
    case Repository = 1;
    case InstallTheApp = 2;
    case Check = 3;
    case ShareAndLabel = 4;
    case Start = 5;

    // Anything but a step's number opens the first step, because a hand-typed or stale address should still land on the flow.
    public static function fromQuery(string $step): self
    {
        $number = filter_var($step, FILTER_VALIDATE_INT);

        return false === $number ? self::Repository : self::tryFrom($number) ?? self::Repository;
    }

    public function label(): string
    {
        return match ($this) {
            self::Repository => 'Repository',
            self::InstallTheApp => 'Install the App',
            self::Check => 'Check',
            self::ShareAndLabel => 'Share and label',
            self::Start => 'Start',
        };
    }

    public function count(): string
    {
        return sprintf('Step %d of %d: %s', $this->value, count(self::cases()), $this->label());
    }

    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    // The steps before the shown one count as done, as in the mock, because the flow keeps no record of which steps the owner finished.
    public function markerAt(self $shown): string
    {
        return match (true) {
            $this === $shown => 'current',
            $this->value < $shown->value => 'done',
            default => 'upcoming',
        };
    }
}
