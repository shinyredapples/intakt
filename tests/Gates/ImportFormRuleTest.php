<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\PhpStan\ImportFormRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ImportFormRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(CodingStandardsGate::class)]
final class ImportFormRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): Rule
    {
        return new ImportFormRule();
    }

    public function testAPlainFunctionAndConstantPassTheCodingStandardsAndStaticAnalysis(): void
    {
        $this->assertAcceptedBy('cs', 'plain-function-and-constant');
        $this->assertAcceptedBy('phpstan', 'plain-function-and-constant');
    }

    public function testAFunctionOrConstantImportFailsStaticAnalysis(): void
    {
        $findings = $this->ruleFindingsOf('imported-function-and-constant');

        self::assertStringContainsString('src/Report.php:7 intakt.functionOrConstantImport', $findings);
        self::assertStringContainsString('src/Report.php:9 intakt.functionOrConstantImport', $findings);
    }

    public function testAnImportAliasWithoutAShortNameClashFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'unneeded-import-alias', 'src/Report.php:7 intakt.unneededImportAlias');
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
}
