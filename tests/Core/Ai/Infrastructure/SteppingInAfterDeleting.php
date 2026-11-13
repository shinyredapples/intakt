<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Override;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

// A cache pool, because only the deleting worker's own call reaches into the moment it still holds its lock, so a test can force another worker's step there.
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
        if ($step instanceof Closure && $this->watched === $key) {
            $this->step = null;
            $step();
        }

        return $deleted;
    }
}
