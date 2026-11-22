<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\StoredJsonEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(StoredJsonEncoder::class)]
final class StoredJsonEncoderTest extends TestCase
{
    public function testAnObjectUnderAMergedKeyAtAnyDepthIsKeptAsAnObject(): void
    {
        $decoded = new StoredJsonEncoder()->decode('{"x":[{"merged":{"0":"B"}}]}', 'json');

        self::assertInstanceOf(stdClass::class, $decoded['x'][0]['merged']);
    }

    public function testAnObjectUnderAnyOtherKeyIsDecodedAsAnArray(): void
    {
        $decoded = new StoredJsonEncoder()->decode('{"x":{"0":"B"}}', 'json');

        self::assertSame(['x' => ['B']], $decoded);
    }
}
