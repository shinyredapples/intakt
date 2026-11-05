<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpunitReport;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PhpunitReport::class)]
final class PhpunitListingDiagnosticsTest extends SandboxTestCase
{
    public function testAWarningTheTestRunnerTriggeredWhileListingIsAFinding(): void
    {
        $log = $this->eventsLog("Test Runner Configured\nTest Runner Triggered PHPUnit Warning (Class MissingTest cannot be found in tests/MissingTest.php)\nTest Suite Loaded (1 test)\n");

        self::assertSame(['sqlite: PHPUnit Warning (Class MissingTest cannot be found in tests/MissingTest.php)'], PhpunitReport::listingDiagnostics('sqlite', $log));
    }

    public function testAListingWithoutTriggeredEventsHasNoFinding(): void
    {
        $log = $this->eventsLog("Test Runner Configured\nTest Runner Triggered Garbage Collection\nTest Suite Loaded (1 test)\nTest Runner Finished\n");

        self::assertSame([], PhpunitReport::listingDiagnostics('sqlite', $log));
    }

    public function testAListingThatWroteNoEventsLogIsAFinding(): void
    {
        self::assertSame(['sqlite: phpunit listed its test files without writing its events log'], PhpunitReport::listingDiagnostics('sqlite', $this->emptySandbox()->root.'/events.txt'));
    }

    private function eventsLog(string $events): string
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('events.txt', $events);

        return $sandbox->root.'/events.txt';
    }
}
