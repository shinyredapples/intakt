<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\Contract;

use Intakt\Core\Job\Contract\ContainerEngine;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerEngine::class)]
final class ContainerEngineTest extends TestCase
{
    #[TestWith([null])]
    #[TestWith([''])]
    public function testAnInstallThatSetsNoEngineHasNone(?string $configured): void
    {
        self::assertFalse(new ContainerEngine($configured)->isConfigured());
    }

    public function testAnInstallThatSetsDockerHasAnEngine(): void
    {
        self::assertTrue(new ContainerEngine('docker')->isConfigured());
    }

    #[TestWith(['podman'])]
    #[TestWith(['Docker'])]
    #[TestWith([' docker'])]
    public function testAnyOtherValueIsRefusedWithTheVariableNamed(string $configured): void
    {
        try {
            new ContainerEngine($configured)->isConfigured();
            self::fail('The value was taken.');
        } catch (InvalidArgumentException $refused) {
            self::assertStringStartsWith('INTAKT_CONTAINER_ENGINE is "'.$configured.'"', $refused->getMessage());
        }
    }
}
