<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

// The one place a figure gets its kind marker, so the console, the JSON and the dashboard label a figure the same way.
final readonly class Figures
{
    public function marked(string $reading, FigureKind $kind): string
    {
        return $reading.' '.$kind->value;
    }

    // Only figures of one kind add up: an estimate added to a reading would pass for a reading.
    public function total(Figure ...$parts): Figure
    {
        $first = array_first($parts) ?? throw MixedFigureKinds::inTotal();
        $amount = 0;

        foreach ($parts as $part) {
            if ($part->kind !== $first->kind) {
                throw MixedFigureKinds::inTotal();
            }

            $amount += $part->amount;
        }

        return new Figure($amount, $first->kind);
    }
}
