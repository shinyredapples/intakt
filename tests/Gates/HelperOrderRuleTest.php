<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\HelperOrderRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<HelperOrderRule>
 */
#[CoversClass(HelperOrderRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class HelperOrderRuleTest extends PhpStanRuleTestCase
{
    /**
     * @return iterable<string, non-empty-list<CheckRun|string>>
     */
    public static function casesOfOneRun(): iterable
    {
        return self::casesOfOnePhpstanRun([
            'APrivateHelperAboveItsCallerFailsStaticAnalysis' => ['helper-above-its-caller', 'src/Report.php:14 intakt.helperOrder Private helper framed() sits above render(), its first caller: move it below render().'],
            'HelpersBelowTheirCallerInCallOrderPassStaticAnalysis' => ['helpers-in-call-order'],
            'APrivateHelperAboveItsCallerInATraitFailsStaticAnalysis' => ['helper-above-its-caller-in-a-trait', 'src/Greets.php:14 intakt.helperOrder Private helper exclaimed() sits above greet(), its first caller: move it below greet().'],
            'HelpersInCallOrderInATraitPassStaticAnalysis' => ['helpers-in-call-order-in-a-trait'],
        ]);
    }

    #[DataProvider('casesOfOneRun')]
    public function testEachFixtureReportsItsFindingsUnderItsFolder(CheckRun $run, string $fixture, string ...$expectedFindings): void
    {
        $this->assertFindingsUnderItsFolder($run, $fixture, ...$expectedFindings);
    }

    public function testAPrivateHelperAboveItsCallerIsReportedWithHowToComply(): void
    {
        self::assertSame(
            'src/Report.php:14 intakt.helperOrder Private helper framed() sits above render(), its first caller: move it below render(). Helpers sit below the method that first calls them, in the order it first calls them.',
            $this->ruleFindingsOf('helper-above-its-caller'),
        );
    }

    public function testAPrivateHelperAboveItsCallerInATraitIsReportedOnceInTheTraitAndNotInTheClassThatUsesIt(): void
    {
        self::assertSame(
            'src/Greets.php:14 intakt.helperOrder Private helper exclaimed() sits above greet(), its first caller: move it below greet(). Helpers sit below the method that first calls them, in the order it first calls them.',
            $this->ruleFindingsOf('helper-above-its-caller-in-a-trait'),
        );
    }

    public function testHelpersInCallOrderInATraitPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('helpers-in-call-order-in-a-trait');
    }

    public function testAPrivateHelperAboveItsCallerInAnAnonymousClassFailsStaticAnalysis(): void
    {
        $this->assertRuleRejects('helper-above-its-caller-in-an-anonymous-class', 'src/Labels.php:12 intakt.helperOrder Private helper trimmed() sits above render(), its first caller: move it below render().');
    }

    public function testTwoStaticHelpersInTheReverseOrderOfTheirFirstCallsFailStaticAnalysis(): void
    {
        self::assertSame(
            'src/Page.php:14 intakt.helperOrder Private helper body() sits above header(), which render() calls first: move it below header(). Helpers sit below the method that first calls them, in the order it first calls them.',
            $this->ruleFindingsOf('helpers-in-reverse-call-order'),
        );
    }

    public function testAHelperAboveTheLaterHelperThatIsItsOnlyCallerFailsStaticAnalysis(): void
    {
        self::assertSame(
            'src/Job.php:19 intakt.helperOrder Private helper tidy() sits above finish(), its first caller: move it below finish(). Helpers sit below the method that first calls them, in the order it first calls them.',
            $this->ruleFindingsOf('helper-above-its-later-caller'),
        );
    }

    public function testHelpersCalledThroughAFirstClassCallableAndAClosureCountAsCalledByTheEnclosingMethod(): void
    {
        $this->assertRuleRejects('helpers-called-through-callable-and-closure', 'src/Names.php:21 intakt.helperOrder Private helper shorten() sits above format(), which cleaned() calls first');
    }

    public function testAHelperCalledFromTwoMethodsMaySitBelowItsFirstCallerAfterTheSecond(): void
    {
        $this->assertRuleAccepts('helper-shared-by-two-methods');
    }

    public function testACallInsideAnAnonymousClassDoesNotCountForTheEnclosingMethod(): void
    {
        $this->assertRuleAccepts('helper-called-inside-an-anonymous-class');
    }

    public function testARecursiveHelperAndTwoUncalledPrivateMethodsCallingEachOtherPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('recursive-and-uncalled-helpers');
    }

    protected function ruleFor(string $root): HelperOrderRule
    {
        return new HelperOrderRule();
    }
}
