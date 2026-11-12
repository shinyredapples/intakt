<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobImageNotBuilt::class)]
final class JobImageNotBuiltTest extends TestCase
{
    public function testAFailedBuildKeepsExactlyItsLastTwentyLines(): void
    {
        $output = implode("\n", array_map(static fn (int $line): string => 'line '.$line, range(1, 21)))."\n";

        $message = JobImageNotBuilt::buildFailed('demo', 'step failed', $output)->getMessage();

        self::assertSame("The image demo was not built: step failed\n".implode("\n", array_map(static fn (int $line): string => 'line '.$line, range(2, 21))), $message);
    }
}
