<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Infrastructure\ComponentOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComponentOptions::class)]
final class ComponentOptionsTest extends TestCase
{
    public function testAKnownNameGetsItsOption(): void
    {
        self::assertSame('Budget', new ComponentOptions()->of(['budget' => 'Budget'], 'budget'));
    }

    public function testAnUnknownNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComponentOptions()->of(['budget' => 'Budget'], 'weather');
    }
}
