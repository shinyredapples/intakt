<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\JudgeDone;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The places a judged round flagged a P1 at, each the key of a P1 on its act list, so a follow-up or a P1 without a key never counts as flagged.
#[Exclude]
final readonly class FlaggedRound
{
    public string $id;

    // The first finding id by key, because one place flagged twice in a round recurs once.
    /** @var array<string, string> */
    public array $places;

    public function __construct(
        public int $number,
        public string $head,
        JudgeDone $done,
    ) {
        $this->id = $done->round;
        $places = [];

        foreach ($done->act as $finding) {
            $key = $done->p1Keys[$finding] ?? null;

            if (null !== $key && !isset($places[$key])) {
                $places[$key] = $finding;
            }
        }

        $this->places = $places;
    }

    public function flagsBefore(self $later, string $key): bool
    {
        return $this->number < $later->number && $this->head !== $later->head && isset($this->places[$key]);
    }
}
