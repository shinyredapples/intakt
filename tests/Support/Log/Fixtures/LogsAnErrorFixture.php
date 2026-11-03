<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Intakt\Tests\Support\Log\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LogsAnErrorFixture extends TestCase
{
    public function testItLogsAnErrorNobodyAsserts(): void
    {
        new RecordingLogger()->error('Disk on fire.');

        self::assertTrue(true);
    }
}
