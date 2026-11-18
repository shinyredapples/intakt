<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\FinalClassRule;
use Intakt\Gates\PhpStan\RepositoryCode;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<FinalClassRule>
 */
#[CoversClass(FinalClassRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
#[UsesClass(RepositoryCode::class)]
final class FinalClassRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): FinalClassRule
    {
        return new FinalClassRule(new RepositoryCode($root));
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
            'AnEntityWithoutFinalFailsStaticAnalysis' => ['non-final-entity', 'src/Note.php:10 intakt.finalClass'],
        ], 'src/');
    }

    public function testANonFinalClassOutsideTheFoldersOfRepositoryCodePassesStaticAnalysis(): void
    {
        $this->assertAcceptedBy('phpstan', 'non-final-class-outside-repository-code');
    }

    public function testANonFinalClassOfTheGatesFailsStaticAnalysis(): void
    {
        $this->assertRejectedBy('phpstan', 'non-final-gate-class', 'config/gates/src/Shape.php:7 intakt.finalClass');
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAnAnonymousClassInsideAFinalClassPassesStaticAnalysis(): void
    {
        $this->assertRuleAccepts('anonymous-class');
    }

    public function testANonFinalClassIsReportedOnTheLineOfItsNameNotItsAttribute(): void
    {
        $this->assertRuleRejects('attributed-non-final-class', 'src/Marker.php:10 intakt.finalClass');
    }
}
