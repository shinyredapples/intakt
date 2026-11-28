<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use RuntimeException;

// Its own failure, because LiveProjectCards sends the cards once more when the hub did not take them and logs only the second refusal.
final class ProjectCardsNotSent extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The hub did not take the project cards.');
    }
}
