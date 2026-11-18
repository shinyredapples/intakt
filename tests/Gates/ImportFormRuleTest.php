<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\PhpStan\ImportFormRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<ImportFormRule>
 */
#[CoversClass(ImportFormRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(CodingStandardsGate::class)]
final class ImportFormRuleTest extends PhpStanRuleTestCase
{
    public function testAPlainFunctionAndConstantPassTheCodingStandards(): void
    {
        $this->assertAcceptedBy('cs', 'plain-function-and-constant');
    }

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'APlainFunctionAndConstantPassesStaticAnalysis' => ['plain-function-and-constant'],
            'AnImportAliasWithoutAShortNameClashFailsStaticAnalysis' => ['unneeded-import-alias', 'src/Report.php:7 intakt.unneededImportAlias'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAFunctionOrConstantImportFailsStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('imported-function-and-constant');

        self::assertStringContainsString('src/Report.php:7 intakt.functionOrConstantImport', $findings);
        self::assertStringContainsString('src/Report.php:9 intakt.functionOrConstantImport', $findings);
    }

    public function testAnImportAliasThatResolvesAShortNameClashPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('import-alias-for-short-name-clash');
    }

    public function testAnImportAliasThatResolvesAShortNameClashInAnotherLetterCasePassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('import-alias-for-short-name-clash-in-another-case');
    }

    public function testAnImportAliasWithoutAShortNameClashInItsOwnNamespaceFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('import-alias-in-another-namespace', 'src/Report.php:17 intakt.unneededImportAlias');
    }

    public function testAnImportAliasForAClassImportedTwiceFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('duplicate-import-alias', 'src/Report.php:8 intakt.unneededImportAlias');
    }

    public function testAnImportAliasForTheShortNameOfTheDeclaredClassPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('import-alias-for-declared-class');
    }

    public function testTheDoctrineOrmAliasPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('doctrine-orm-alias');
    }

    protected function ruleFor(string $root): ImportFormRule
    {
        return new ImportFormRule();
    }
}
