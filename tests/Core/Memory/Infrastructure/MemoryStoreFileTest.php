<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

#[CoversNothing]
#[Group('persistence')]
// Because the memory store's file is var/memory_test.db of the checkout, not the worker's database, and the test removes it.
#[Group('serial')]
final class MemoryStoreFileTest extends KernelTestCase
{
    public function testTheMemoryStoreKeepsItsFileForTheKernelEnvironment(): void
    {
        $file = self::bootKernel()->getProjectDir().'/var/memory_test.db';
        if (is_file($file)) {
            unlink($file);
        }
        $store = self::getContainer()->get('ai.store.sqlite.memory');

        $store->setup();

        self::assertFileExists($file);
    }
}
