<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\FinalClassRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<FinalClassRule>
 */
#[CoversClass(FinalClassRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class FinalClassRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): FinalClassRule
    {
        return new FinalClassRule();
    }

    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'AFinalReadonlyServicePassesStaticAnalysis' => ['final-readonly-service'],
            'AFinalEntityPassesStaticAnalysis' => ['final-entity'],
            'AnAbstractClassPassesStaticAnalysis' => ['abstract-class'],
            'AReadonlyClassWithoutFinalFailsStaticAnalysis' => ['non-final-readonly-class', 'src/Mailer.php:7 intakt.finalClass'],
            'AnEntityWithoutFinalFailsStaticAnalysis' => ['non-final-entity', 'src/Note.php:9 intakt.finalClass'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }
}
