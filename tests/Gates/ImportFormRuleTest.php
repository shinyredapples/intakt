<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CodingStandardsGate;
use Intakt\Gates\PhpStan\ImportFormRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ImportFormRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(CodingStandardsGate::class)]
final class ImportFormRuleTest extends SandboxTestCase
{
    public function testAPlainFunctionAndConstantPassTheCodingStandardsAndStaticAnalysis(): void
    {
        $this->assertAcceptedBy('cs', 'plain-function-and-constant');
        $this->assertAcceptedBy('phpstan', 'plain-function-and-constant');
    }

    public function testAFunctionOrConstantImportFailsStaticAnalysis(): void
    {
        $run = $this->sandboxWith('imported-function-and-constant')->check('--only=phpstan');

        self::assertTrue($run->failed('phpstan'), $run->output());
        self::assertStringContainsString('src/Report.php:7 intakt.functionOrConstantImport', $run->findingsOf('phpstan'));
        self::assertStringContainsString('src/Report.php:9 intakt.functionOrConstantImport', $run->findingsOf('phpstan'));
    }

    public function testAnImportAliasWithoutAShortNameClashFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'unneeded-import-alias', 'src/Report.php:7 intakt.unneededImportAlias');
    }

    public function testAnImportAliasThatResolvesAShortNameClashPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'import-alias-for-short-name-clash');
    }

    public function testAnImportAliasThatResolvesAShortNameClashInAnotherLetterCasePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'import-alias-for-short-name-clash-in-another-case');
    }

    public function testAnImportAliasWithoutAShortNameClashInItsOwnNamespaceFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'import-alias-in-another-namespace', 'src/Report.php:17 intakt.unneededImportAlias');
    }

    public function testAnImportAliasForAClassImportedTwiceFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'duplicate-import-alias', 'src/Report.php:8 intakt.unneededImportAlias');
    }

    public function testAnImportAliasForTheShortNameOfTheDeclaredClassPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'import-alias-for-declared-class');
    }

    public function testTheDoctrineOrmAliasPassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'doctrine-orm-alias');
    }
}
