<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Memory\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class MemoryStoreFileTest extends KernelTestCase
{
    public function testTheMemoryStoreKeepsItsFileForTheKernelEnvironment(): void
    {
        $file = self::bootKernel()->getProjectDir().'/var/memory_test.db';
        if (is_file($file)) {
            unlink($file);
        }
        $store = self::getContainer()->get('ai.store.sqlite.memory');
        assert($store instanceof ManagedStoreInterface);

        $store->setup();

        self::assertFileExists($file);
    }
}
