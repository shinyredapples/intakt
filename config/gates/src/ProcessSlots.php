<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class ProcessSlots
{
    /**
     * @param list<array-key> $oneAtATime
     */
    public function __construct(
        private int $count,
        private array $oneAtATime = [],
    ) {
    }

    /**
     * @template Item
     *
     * @param array<array-key, Item>  $waiting
     * @param array<array-key, mixed> $running
     *
     * @return array<array-key, Item>
     */
    public function nextToStart(array $waiting, array $running): array
    {
        $free = max(1, $this->count) - count($running);
        $turnTaken = array_any(array_keys($running), $this->takesTurns(...));
        $starting = [];

        foreach ($waiting as $name => $item) {
            if (count($starting) >= $free) {
                break;
            }

            if ($this->takesTurns($name)) {
                if ($turnTaken) {
                    continue;
                }

                $turnTaken = true;
            }

            $starting[$name] = $item;
        }

        return $starting;
    }

    private function takesTurns(int|string $name): bool
    {
        return in_array($name, $this->oneAtATime, true);
    }
}
