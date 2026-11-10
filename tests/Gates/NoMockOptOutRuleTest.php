<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoMockOptOutRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(NoMockOptOutRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoMockOptOutRuleTest extends SandboxTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'OptingOutOfMockExpectationsFailsStaticAnalysis' => ['mock-opt-out', 'src/SampleTest.php:10 intakt.mockOptOut', 'src/SampleTest.php:15 staticMethod.alreadyNarrowedType'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }
}
