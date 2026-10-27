<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Infrastructure\DisabledProvidersWarning;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(DisabledProvidersWarning::class)]
final class DisabledProvidersWarningTest extends TestCase
{
    public function testEachDisabledProviderIsLoggedAsAWarningWhenTheCacheIsWarmed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning')->with(self::logicalOr('first message', 'second message'));

        $warning = new DisabledProvidersWarning(['a' => 'first message', 'b' => 'second message'], $logger);

        self::assertSame([], $warning->warmUp('cache'));
    }

    public function testEachDisabledProviderIsLoggedWhenACommandStarts(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('only message');

        new DisabledProvidersWarning(['a' => 'only message'], $logger)->onConsoleCommand();
    }

    public function testNothingIsLoggedWhenEveryProviderIsEnabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        new DisabledProvidersWarning([], $logger)->onConsoleCommand();
    }
}
