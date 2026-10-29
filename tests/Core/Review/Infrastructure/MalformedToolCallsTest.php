<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\MalformedToolCalls;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;

#[CoversClass(MalformedToolCalls::class)]
final class MalformedToolCallsTest extends TestCase
{
    public function testAResetForgetsAMalformedCallItSaw(): void
    {
        $inner = self::createStub(ToolboxInterface::class);
        $inner->method('execute')->willThrowException(new ToolNotFoundException('Tool "write_file" was not found.'));
        $calls = new MalformedToolCalls($inner);
        try {
            $calls->execute(new ToolCall('1', 'write_file'));
        } catch (ToolNotFoundException) {
        }

        $calls->reset();

        self::assertFalse($calls->seen());
    }
}
