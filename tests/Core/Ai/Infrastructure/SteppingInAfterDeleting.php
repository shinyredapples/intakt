<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Override;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

// Runs another worker's step once, right after the watched key is deleted, to force that step into the moment the deleting worker still holds its lock.
final class SteppingInAfterDeleting extends ArrayAdapter
{
    public ?Closure $step = null;

    public function __construct(private readonly string $watched)
    {
        parent::__construct();
    }

    #[Override]
    public function deleteItem(mixed $key): bool
    {
        $deleted = parent::deleteItem($key);
        $step = $this->step;
        if (null !== $step && $this->watched === $key) {
            $this->step = null;
            $step();
        }

        return $deleted;
    }
}
