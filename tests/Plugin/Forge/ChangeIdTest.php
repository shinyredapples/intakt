<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\Forge;

use Intakt\Plugin\Forge\Contract\ChangeId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeId::class)]
final class ChangeIdTest extends TestCase
{
    public function testABlankChangeIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChangeId(' ');
    }
}
