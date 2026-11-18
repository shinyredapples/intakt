<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\ClockOnlyRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @extends PhpStanRuleTestCase<ClockOnlyRule>
 */
#[CoversClass(ClockOnlyRule::class)]
final class ClockOnlyRuleTest extends PhpStanRuleTestCase
{
    private const int FIRST_CASE_LINE = 11;

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function rejectedGroups(): iterable
    {
        yield 'time functions' => [['time()', "date('Y')", 'microtime(true)', 'date_create()', 'date_create_immutable()', "strtotime('2026-01-01')", 'mktime(0)']];
        yield 'more time functions' => [["gmdate('Y')", 'getdate()', 'localtime()', 'gettimeofday()', 'new \DateTime()', "\\DateTime::createFromFormat('Y', '2026')"]];
        yield 'moments that read the clock' => [['new \DateTimeImmutable()', "new \\DateTimeImmutable('now')", "new \\DateTimeImmutable('today')", "new \\DateTimeImmutable('+1 day')", "new \\DateTimeImmutable('yesterday')", 'new \DateTimeImmutable($moment)', "\\DateTimeImmutable::createFromFormat('Y-m-d', \$moment)"]];
    }

    /**
     * @param list<string> $expressions
     */
    #[DataProvider('rejectedGroups')]
    public function testEachWayOfReadingTheSystemTimeIsRejected(array $expressions): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning($expressions));

        foreach (array_keys($expressions) as $index) {
            self::assertStringContainsString('src/Report.php:'.(self::FIRST_CASE_LINE + $index * 5).' intakt.clockOnly', $findings, $expressions[$index]);
        }
    }

    public function testAbsoluteMomentsAndExplicitTimeZonesPass(): void
    {
        $findings = $this->ruleFindingsIn($this->sandboxReturning([
            "new \\DateTimeImmutable('2026-10-27T10:00:00Z')",
            "new \\DateTimeImmutable('@1793000000')",
            "\\DateTimeImmutable::createFromFormat('Y-m-d', \$moment, new \\DateTimeZone('UTC'))",
            '$clock->now()',
        ]));

        self::assertSame('', $findings);
    }

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'TheClockAdapterMayReadTheSystemTime' => ['clock-adapter'],
            'AClassBesideTheClockAdapterMayNot' => ['clock-beside-adapter', 'src/Core/Platform/Infrastructure/Clock/OtherClock.php:11 intakt.clockOnly'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    protected function ruleFor(string $root): ClockOnlyRule
    {
        return new ClockOnlyRule();
    }

    /**
     * @param list<string> $expressions
     */
    private function sandboxReturning(array $expressions): Sandbox
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Clock.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\ninterface Clock\n{\n    public function now(): \\DateTimeImmutable;\n}\n");
        $sandbox->write('src/Report.php', $this->classReturning($expressions));

        return $sandbox;
    }

    /**
     * @param list<string> $expressions
     */
    private function classReturning(array $expressions): string
    {
        $methods = array_map(
            static fn (int $index, string $expression): string => "    public function case{$index}(string \$moment, Clock \$clock): mixed\n    {\n        return {$expression};\n    }\n",
            array_keys($expressions),
            $expressions,
        );

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Intakt\\Sample;\n\nfinal readonly class Report\n{\n".implode("\n", $methods)."}\n";
    }
}
