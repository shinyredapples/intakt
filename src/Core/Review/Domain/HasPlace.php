<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// The marking store reads and writes the state through this, because the state column is private and Symfony's method store would need a public setter.
interface HasPlace
{
    public function place(): string;

    public function moveTo(string $place): void;
}
