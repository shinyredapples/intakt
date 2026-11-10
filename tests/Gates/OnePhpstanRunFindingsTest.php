<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessResult;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class OnePhpstanRunFindingsTest extends TestCase
{
    use UsesSandboxes;

    public function testAnAcceptedFixtureFailsWhenTheGateOmittedSomeFindings(): void
    {
        $run = $this->runReporting('{"gate":"phpstan","ok":false,"findings":["rejected/src/Lookup.php:9 intakt.containerUnknownGetId"],"omitted":1}');

        $this->expectException(AssertionFailedError::class);

        $this->assertFindingsUnderItsFolder($run, 'accepted');
    }

    public function testAnAcceptedFixtureFailsWhenTheGateFailedWithOnlyANote(): void
    {
        $run = $this->runReporting('{"gate":"phpstan","ok":false,"findings":[],"omitted":0,"note":"PHPStan crashed"}');

        $this->expectException(AssertionFailedError::class);

        $this->assertFindingsUnderItsFolder($run, 'accepted');
    }

    public function testAnAcceptedFixturePassesWhenTheGateReportedEveryFinding(): void
    {
        $run = $this->runReporting('{"gate":"phpstan","ok":false,"findings":["rejected/src/Lookup.php:9 intakt.containerUnknownGetId"],"omitted":0}');

        $this->assertFindingsUnderItsFolder($run, 'accepted');
        $this->assertFindingsUnderItsFolder($run, 'rejected', 'src/Lookup.php:9 intakt.containerUnknownGetId');
    }

    private function runReporting(string $gateLine): CheckRun
    {
        return new CheckRun(new ProcessResult(1, $gateLine, ''));
    }
}
