<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// The path every change walks, in order, so a Working now card can mark the steps behind, on and ahead of it (UX2 home, #3326).
enum WorkStep: string
{
    case Plan = 'Plan';
    case Build = 'Build';
    case Check = 'Check';
    case Review = 'Review';
    case Merge = 'Merge';

    public function comesBefore(self $other): bool
    {
        return $this->position() < $other->position();
    }

    // Counted from one, because the progress line's label reads it that way ("step 2 of 5").
    public function position(): int
    {
        return (int) array_search($this, self::cases(), true) + 1;
    }

    public function doing(): string
    {
        return match ($this) {
            self::Plan => 'Planning',
            self::Build => 'Building',
            self::Check => 'Checking',
            self::Review => 'Reviewing',
            self::Merge => 'Merging',
        };
    }
}
