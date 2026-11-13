<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Override;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

// A cache pool, because only the reading worker's own call reaches into the moment between its look at an item and the lock it takes on it, so a test can force other workers' steps there.
final class SteppingInAfterReading extends ArrayAdapter
{
    public ?Closure $step = null;

    public function __construct(private readonly string $watched)
    {
        parent::__construct();
    }

    #[Override]
    public function getItem(mixed $key): CacheItem
    {
        $item = parent::getItem($key);
        $step = $this->step;
        if ($step instanceof Closure && $this->watched === $key && $item->isHit()) {
            $this->step = null;
            $step();
        }

        return $item;
    }
}
