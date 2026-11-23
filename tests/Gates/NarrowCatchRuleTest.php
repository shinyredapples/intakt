<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\PhpStan\NarrowCatchRule;
use Intakt\Gates\PhpStan\RunningCode;
use Intakt\Gates\StaticAnalysisGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * @extends PhpStanRuleTestCase<NarrowCatchRule>
 */
#[CoversClass(NarrowCatchRule::class)]
#[CoversClass(RunningCode::class)]
#[UsesClass(StaticAnalysisGate::class)]
final class NarrowCatchRuleTest extends PhpStanRuleTestCase
{
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

    public function testAMarkedThrowableCatchThatReturnsATypedOutcomeWithoutBindingTheFailurePassesStaticAnalysis(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Work.php', <<<'PHP'
            <?php
            enum WorkOutcome { case Done; case Failed; }
            final class Work
            {
                public function run(callable $perform): WorkOutcome
                {
                    try {
                        $perform();
                    }
                    // Boundary: callers receive an explicit failure outcome
                    catch (Throwable) {
                        return WorkOutcome::Failed;
                    }

                    return WorkOutcome::Done;
                }
            }
            PHP);

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function markedCatchBodiesThatLoseTheFailure(): iterable
    {
        yield 'a return of null' => ['return null;'];
        yield 'a bare return' => ['return;'];
        yield 'a throw inside a closure that is never called' => ['$never = static fn () => throw new \LogicException();'];
        yield 'a use of the failure inside a closure that is never called' => ['$never = static fn () => $failure;'];
        yield 'a typed outcome returned inside an anonymous class' => ['$never = new class { public function outcome(): int { return 1; } };'];
    }

    #[DataProvider('markedCatchBodiesThatLoseTheFailure')]
    public function testAMarkedThrowableCatchWhoseBodyLosesTheFailureFailsStaticAnalysis(string $body): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Lossy.php', <<<PHP
            <?php
            final class Lossy
            {
                public function run(callable \$perform): ?int
                {
                    try {
                        \$perform();
                    }
                    // Boundary: the caller goes on without the result
                    catch (Throwable \$failure) {
                        {$body}
                    }

                    return 0;
                }
            }
            PHP);

        self::assertStringContainsString('src/Lossy.php:10 intakt.narrowCatch A catch of Throwable at a failure boundary that neither rethrows nor records the failure', $this->ruleFindingsIn($sandbox));
    }

    public function testAMarkedThrowableCatchThatLogsWithoutBindingTheFailureFailsStaticAnalysis(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Worker.php', <<<'PHP'
            <?php
            use Psr\Log\LoggerInterface;

            final readonly class Worker
            {
                public function __construct(private LoggerInterface $logger)
                {
                }

                public function run(callable $work): void
                {
                    try {
                        $work();
                    }
                    // Boundary: keeps one failed job from stopping the worker
                    catch (Throwable) {
                        $this->logger->error('The job failed.');
                    }
                }
            }
            PHP);

        self::assertStringContainsString('src/Worker.php:16 intakt.narrowCatch A catch of Throwable at a failure boundary that neither rethrows nor records the failure', $this->ruleFindingsIn($sandbox));
    }

    public function testAMarkedThrowableCatchThatLogsTheBoundFailurePassesStaticAnalysis(): void
    {
        $sandbox = $this->emptySandbox();
        $sandbox->write('src/Worker.php', <<<'PHP'
            <?php
            use Psr\Log\LoggerInterface;

            final readonly class Worker
            {
                public function __construct(private LoggerInterface $logger)
                {
                }

                public function run(callable $work): void
                {
                    try {
                        $work();
                    }
                    // Boundary: keeps one failed job from stopping the worker
                    catch (Throwable $failure) {
                        $this->logger->error('The job failed.', ['exception' => $failure]);
                    }
                }
            }
            PHP);

        self::assertSame('', $this->ruleFindingsIn($sandbox));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineEndings(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'CR' => ["\r"];
    }

    #[DataProvider('lineEndings')]
    public function testABoundaryCommentOnItsOwnLinePassesAndOneTrailingCodeFailsWithAnyLineEnding(string $ending): void
    {
        $sandbox = $this->emptySandbox();
        $source = <<<'PHP'
            <?php
            function marked(callable $work): void {
                try {
                    $work();
                }
                // Boundary: preserves failures for the caller
                catch (Throwable $failure) {
                    throw $failure;
                }
            }
            function trailing(callable $work): void {
                try {
                    $work(); // Boundary: preserves failures for the caller
                } catch (Throwable $failure) {
                    throw $failure;
                }
            }
            PHP;
        $sandbox->write('src/Boundary.php', str_replace("\n", $ending, $source));

        $findings = $this->ruleFindingsIn($sandbox);

        self::assertSame(1, substr_count($findings, 'intakt.narrowCatch'), $findings);
        self::assertStringContainsString('src/Boundary.php:14 intakt.narrowCatch', $findings);
    }

    public function testLibraryExceptionsSharingARootsShortNamePassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('narrow-catch-library-exceptions');
    }

    public function testGenericCatchesOutsideTheSourceFolderPassStaticAnalysis(): void
    {
        $this->assertRuleAccepts('narrow-catch-outside-src');
    }

    protected function ruleFor(string $root): NarrowCatchRule
    {
        return new NarrowCatchRule($root);
    }
}
