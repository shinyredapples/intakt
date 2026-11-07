<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Lock\LockFactory;

#[CoversNothing]
final class LockDirectoryTest extends KernelTestCase
{
    public function testInTheTestEnvironmentLocksLiveInTheCacheDirectoryOfTheWorker(): void
    {
        $directory = self::bootKernel()->getCacheDir().'/locks';
        $locks = self::getContainer()->get(LockFactory::class);
        self::assertInstanceOf(LockFactory::class, $locks);
        $lock = $locks->createLock('lock-directory-test');

        try {
            self::assertTrue($lock->acquire());
            self::assertNotEmpty(glob($directory.'/*lock-directory-test*'));
        } finally {
            $lock->release();
        }
    }
}
