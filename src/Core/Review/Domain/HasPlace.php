<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// What a workflow marking store needs from a subject whose state machine is in exactly one place.
interface HasPlace
{
    public function place(): string;

    public function moveTo(string $place): void;
}
