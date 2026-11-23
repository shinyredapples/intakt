<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Closure;
use Override;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

// A cache pool that refuses one save without throwing and runs another worker's step first, because only the saving worker's own call reaches into the moment between its window's read and its refused save.
final class RefusingASaveAfterAStep extends ArrayAdapter
{
    public ?Closure $step = null;

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        $step = $this->step;
        if (!$step instanceof Closure) {
            return parent::save($item);
        }

        $this->step = null;
        $step();

        return false;
    }
}
