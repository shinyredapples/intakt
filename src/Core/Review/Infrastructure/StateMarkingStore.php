<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use InvalidArgumentException;
use Intakt\Core\Review\Domain\HasPlace;
use Override;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

// The state lives in a database column of the subject, so a restarted worker finds the machine where it stopped.
final readonly class StateMarkingStore implements MarkingStoreInterface
{
    #[Override]
    public function getMarking(object $subject): Marking
    {
        return new Marking([$this->placed($subject)->place() => 1]);
    }

    #[Override]
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        $place = array_key_first($marking->getPlaces());

        if (!is_string($place)) {
            throw new InvalidArgumentException('A state machine is in exactly one place.');
        }

        $this->placed($subject)->moveTo($place);
    }

    private function placed(object $subject): HasPlace
    {
        return $subject instanceof HasPlace ? $subject : throw new InvalidArgumentException('The subject does not keep its place: '.$subject::class);
    }
}
