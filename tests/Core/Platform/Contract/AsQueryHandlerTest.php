<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Contract;

use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Platform\Contract\Bus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsQueryHandler::class)]
#[UsesClass(Bus::class)]
final class AsQueryHandlerTest extends TestCase
{
    public function testTheQueryAttributeTargetsTheQueryBusAndPassesItsArgumentsThrough(): void
    {
        $attribute = new AsQueryHandler(handles: 'Message', method: 'run', priority: 5);

        self::assertSame(Bus::Query->value, $attribute->bus);
        self::assertSame('Message', $attribute->handles);
        self::assertSame('run', $attribute->method);
        self::assertSame(5, $attribute->priority);
    }
}
