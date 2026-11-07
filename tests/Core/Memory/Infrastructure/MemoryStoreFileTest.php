<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\Store\ManagedStoreInterface;

#[CoversNothing]
#[Group('persistence')]
final class MemoryStoreFileTest extends KernelTestCase
{
    public function testInTheTestEnvironmentTheMemoryStoreKeepsItsFileInTheCacheDirectoryOfTheWorker(): void
    {
        $file = self::bootKernel()->getCacheDir().'/memory.db';
        if (is_file($file)) {
            unlink($file);
        }
        $store = self::getContainer()->get('ai.store.sqlite.memory');
        assert($store instanceof ManagedStoreInterface);

        $store->setup();

        self::assertFileExists($file);
    }
}
