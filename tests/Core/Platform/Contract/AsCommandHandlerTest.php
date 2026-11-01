<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Contract;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\Bus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsCommandHandler::class)]
#[UsesClass(Bus::class)]
final class AsCommandHandlerTest extends TestCase
{
    public function testTheCommandAttributeTargetsTheCommandBusAndPassesItsArgumentsThrough(): void
    {
        $attribute = new AsCommandHandler(handles: 'Message', method: 'run', priority: 5);

        self::assertSame(Bus::Command->value, $attribute->bus);
        self::assertSame('Message', $attribute->handles);
        self::assertSame('run', $attribute->method);
        self::assertSame(5, $attribute->priority);
    }
}
