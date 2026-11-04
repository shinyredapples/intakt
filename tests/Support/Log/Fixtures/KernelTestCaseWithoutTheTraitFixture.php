<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversNothing]
final class KernelTestCaseWithoutTheTraitFixture extends KernelTestCase
{
    public function testItBootsTheKernel(): void
    {
        self::assertNotNull(self::bootKernel()->getContainer());
    }
}
