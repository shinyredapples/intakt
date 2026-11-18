<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Contract\ActEntryAnswer;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ReplayMark;
use JsonSerializable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
final readonly class RoundActList implements JsonSerializable
{
    /**
     * @param list<Finding> $act
     */
    public function __construct(
        public string $roundId,
        public bool $judged,
        public array $act,
        public ReplayMark $mark,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function jsonSerialize(): array
    {
        return [...get_object_vars($this), 'act' => ActEntryAnswer::listOf($this->act)];
    }
}
