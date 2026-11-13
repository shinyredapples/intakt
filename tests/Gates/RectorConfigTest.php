<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Rector\Config\RectorConfig;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Configuration\RectorConfigBuilder;

// Rector's defaults (a worker per core, 120 s) let concurrent checks time each other out, so the cap must stay.
#[CoversNothing]
final class RectorConfigTest extends TestCase
{
    public function testRectorRunsAtMostFourWorkersWithFiveMinutesEach(): void
    {
        $configure = require __DIR__.'/../../config/gates/rector.php';
        self::assertInstanceOf(RectorConfigBuilder::class, $configure);

        $configure(new RectorConfig());

        self::assertTrue(SimpleParameterProvider::provideBoolParameter(Option::PARALLEL, false));
        self::assertSame(4, SimpleParameterProvider::provideIntParameter(Option::PARALLEL_MAX_NUMBER_OF_PROCESSES));
        self::assertSame(300, SimpleParameterProvider::provideIntParameter(Option::PARALLEL_JOB_TIMEOUT_IN_SECONDS));
    }
}
