<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NarrowCatchRule;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NarrowCatchRule>
 */
#[CoversClass(NarrowCatchRule::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NarrowCatchRuleTest extends PhpStanRuleTestCase
{
    protected function ruleFor(string $root): NarrowCatchRule
    {
        return new NarrowCatchRule($root);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function splRootCatches(): iterable
    {
        yield 'Exception' => ['src/RootCatches.php:17 intakt.narrowCatch A catch of Exception:'];
        yield 'Error' => ['src/RootCatches.php:25 intakt.narrowCatch A catch of Error:'];
        yield 'RuntimeException' => ['src/RootCatches.php:33 intakt.narrowCatch A catch of RuntimeException:'];
        yield 'LogicException' => ['src/RootCatches.php:41 intakt.narrowCatch A catch of LogicException:'];
        yield 'InvalidArgumentException' => ['src/RootCatches.php:49 intakt.narrowCatch A catch of InvalidArgumentException:'];
        yield 'a root in a multi-catch union' => ['src/RootCatches.php:57 intakt.narrowCatch A catch of RuntimeException:'];
        yield 'a root written in lower case' => ['src/RootCatches.php:65 intakt.narrowCatch A catch of runtimeexception:'];
        yield 'a root with a boundary comment above it' => ['src/RootCatches.php:74 intakt.narrowCatch A catch of Exception:'];
    }

    #[DataProvider('splRootCatches')]
    public function testACatchOfAnSplRootFailsStaticAnalysis(string $expectedFinding): void
    {
        $this->assertRuleRejects('narrow-catch-spl-roots', $expectedFinding);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unmarkedThrowableCatches(): iterable
    {
        yield 'without a comment' => [15];
        yield 'with a blank line below the comment' => [25];
        yield 'with a comment that is not a boundary' => [34];
        yield 'with a boundary comment that names nothing' => [43];
        yield 'with the comment above the last statement instead' => [52];
        yield 'in a second catch the comment is not directly above' => [63];
        yield 'with a block comment' => [72];
        yield 'with the comment trailing the last statement' => [102];
    }

    #[DataProvider('unmarkedThrowableCatches')]
    public function testAThrowableCatchWithoutABoundaryCommentDirectlyAboveItFailsStaticAnalysis(int $line): void
    {
        $this->assertRuleRejects('narrow-catch-throwable-without-boundary', sprintf('src/UnmarkedCatches.php:%d intakt.narrowCatch A catch of Throwable outside a failure boundary', $line));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function boundariesThatLoseTheFailure(): iterable
    {
        yield 'an empty catch' => [82];
        yield 'a catch that never uses the failure' => [91];
    }

    #[DataProvider('boundariesThatLoseTheFailure')]
    public function testAMarkedThrowableCatchThatNeitherRethrowsNorRecordsFailsStaticAnalysis(int $line): void
    {
        $this->assertRuleRejects('narrow-catch-throwable-without-boundary', sprintf('src/UnmarkedCatches.php:%d intakt.narrowCatch A catch of Throwable at a failure boundary that neither rethrows nor records the failure', $line));
    }

    public function testOnlyTheUnmarkedThrowableCatchesAreReported(): void
    {
        $findings = $this->ruleFindingsOf('narrow-catch-throwable-without-boundary');

        self::assertSame(10, substr_count($findings, 'intakt.narrowCatch'));
        self::assertStringNotContainsString('src/UnmarkedCatches.php:61 ', $findings);
    }

    public function testAGenericCatchInASourceTraitNoClassUsesFailsStaticAnalysis(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Unattached.php', <<<'PHP'
            <?php
            trait Unattached
            {
                public function work(callable $job): void
                {
                    try {
                        $job();
                    } catch (\RuntimeException) {
                    }
                }
            }
            PHP);

        self::assertStringContainsString('src/Unattached.php:8 intakt.narrowCatch', $this->ruleFindingsIn($sandbox));
    }

    public function testAGenericCatchInATestTraitUsedBySourcePassesStaticAnalysis(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('tests/CatchFromTests.php', <<<'PHP'
            <?php
            trait CatchFromTests
            {
                public function work(callable $job): void
                {
                    try {
                        $job();
                    } catch (\RuntimeException) {
                    }
                }
            }
            PHP);
        $sandbox->write('src/UsingTestTrait.php', '<?php final class UsingTestTrait { use CatchFromTests; }');

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    public function testThrowableCatchesWithABoundaryCommentDirectlyAbovePassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('narrow-catch-throwable-at-boundary');
    }

    public function testLibraryExceptionsSharingARootsShortNamePassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('narrow-catch-library-exceptions');
    }

    public function testGenericCatchesOutsideTheSourceFolderPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('narrow-catch-outside-src');
    }
}
