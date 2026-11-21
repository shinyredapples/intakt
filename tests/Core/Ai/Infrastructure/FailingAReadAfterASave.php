<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Override;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;

// A cache pool whose first read after an accepted save throws once, because only a forced order reaches the moment right after a cooldown's window was kept.
final class FailingAReadAfterASave extends ArrayAdapter
{
    private bool $saved = false;

    #[Override]
    public function getItem(mixed $key): CacheItem
    {
        if ($this->saved) {
            $this->saved = false;

            throw new RuntimeException('The cache could not be read.');
        }

        return parent::getItem($key);
    }

    #[Override]
    public function save(CacheItemInterface $item): bool
    {
        $this->saved = parent::save($item);

        return $this->saved;
    }
}
