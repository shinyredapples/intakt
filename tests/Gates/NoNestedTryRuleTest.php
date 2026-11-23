<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NoNestedTryRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NoNestedTryRule>
 */
#[CoversClass(NoNestedTryRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NoNestedTryRuleTest extends PhpStanRuleTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'ATryInsideACatchFailsStaticAnalysis' => ['nested-try', 'src/Report.php:16 intakt.nestedTry'],
            'TriesInSeparateMethodsPassStaticAnalysis' => ['sibling-tries'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testATryInsideATryFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('try-in-try', 'intakt.nestedTry');
    }

    public function testATryInsideAFinallyFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('try-in-finally', 'intakt.nestedTry');
    }

    public function testATryInAClosureInsideATryPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('try-in-closure');
    }

    protected function ruleFor(string $root): NoNestedTryRule
    {
        return new NoNestedTryRule();
    }
}
