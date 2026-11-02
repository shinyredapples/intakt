<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

// Runs another worker right before the first item is deleted, because that is the moment its report could slip in between a comparison and the deletion.
final class PoolInterleavingTheFirstDelete extends ArrayAdapter
{
    private ?Closure $otherWorker;

    public function __construct(Closure $otherWorker)
    {
        parent::__construct();
        $this->otherWorker = $otherWorker;
    }

    public function deleteItem(mixed $key): bool
    {
        $otherWorker = $this->otherWorker;
        $this->otherWorker = null;
        if ($otherWorker instanceof Closure) {
            $otherWorker();
        }

        return parent::deleteItem($key);
    }
}
