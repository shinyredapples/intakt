<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorageFactory;

#[CoversNothing]
final class TestSessionStorageTest extends KernelTestCase
{
    public function testTheTestEnvironmentUsesTheMockFileSessionStorageWithoutAnExplicitFactory(): void
    {
        self::bootKernel();

        self::assertInstanceOf(MockFileSessionStorageFactory::class, self::getContainer()->get('session.storage.factory'));
    }
}
