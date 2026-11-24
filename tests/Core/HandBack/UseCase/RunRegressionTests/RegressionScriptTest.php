<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\RunRegressionTests;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The job's script on a real mirror and bundle, with this repository's PHPUnit linked in as the install step, because a job container is not available to a test.
#[CoversNothing]
final class RegressionScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/regression.sh';

    private const string VENDOR = __DIR__.'/../../../../../vendor';

    private const string TEST = 'tests/ValueTest.php';

    private const string ASSERTS_TWO = <<<'PHP_WRAP'
    <?php
    final class ValueTest extends \PHPUnit\Framework\TestCase
    {
        public function testTheValueIsTwo(): void
        {
            self::assertSame(2, require __DIR__.'/../src/value.php');
        }
    }
    PHP_WRAP;

    private BuildHistory $history;

    private string $base = '';

    public function testTheBaseRunFailsWhenTheHeadsTestFailsAnAssertionOnTheBaseCode(): void
    {
        $this->history->commitFile(self::TEST, self::ASSERTS_TWO, 'Cover the value');

        self::assertSame("failed\n", $this->baseResult());
    }

    public function testTheHeadRunPassesWhenTheTestPassesOnTheHead(): void
    {
        $head = $this->history->commitFile(self::TEST, self::ASSERTS_TWO, 'Cover the value');

        self::assertSame("passed\n", $this->headResult($head));
    }

    public function testTheBaseRunPassesWhenTheTestDoesNotCatchTheBug(): void
    {
        $this->history->commitFile(self::TEST, str_replace('assertSame(2,', 'assertGreaterThan(0,', self::ASSERTS_TWO), 'Cover the value');

        self::assertSame("passed\n", $this->baseResult());
    }

    public function testATestThatErrorsBeforeItsAssertionOnTheBaseIsErroredAndNotFailed(): void
    {
        $this->history->commitFile('tests/fixture.php', '<?php return 2;', 'Add a fixture');
        $this->history->commitFile(self::TEST, str_replace('assertSame(2,', "assertSame(require __DIR__.'/fixture.php',", self::ASSERTS_TWO), 'Cover the value');

        self::assertSame("errored 3\n", $this->baseResult());
    }

    public function testAHeadWhoseOnlyTestSkipsDoesNotPass(): void
    {
        $head = $this->history->commitFile(self::TEST, str_replace('self::assertSame(', "self::markTestSkipped('unavailable');\n        self::assertSame(", self::ASSERTS_TWO), 'Cover the value');

        self::assertSame("errored 3\n", $this->headResult($head));
    }

    public function testTheBaseRunLaysTheHeadsTestFileOverTheBase(): void
    {
        $this->history->commitFile(self::TEST, self::ASSERTS_TWO, 'Cover the value');

        self::assertSame("passed\n", $this->scriptResult([$this->base, 'task', '1', '-', self::TEST, 'test -f '.self::TEST]));
        self::assertSame("failed\n", $this->scriptResult([$this->base, '-', '0', '-', 'test -f '.self::TEST]));
    }

    public function testTheOverlayTakesASelectedPathLiterally(): void
    {
        $this->history->commitFile('tests/Example[2]Test.php', '<?php', 'Add a test');
        $this->history->commitFile('tests/Example2Test.php', '<?php', 'Add another test');

        self::assertSame("passed\n", $this->scriptResult([$this->base, 'task', '1', '-', 'tests/Example[2]Test.php', "test -f 'tests/Example[2]Test.php' && test ! -f tests/Example2Test.php"]));
    }

    public function testAnInstallStepThatFailsLeavesNotInstalled(): void
    {
        self::assertSame("not-installed\n", $this->scriptResult([$this->base, '-', '0', 'false', 'true']));
    }

    public function testABundleOfAnotherBranchLeavesNotApplied(): void
    {
        self::assertSame("not-applied\n", $this->scriptResult([$this->base, '-', '0', '-', 'true'], 'other'));
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->base = $this->history->commitFile('src/value.php', '<?php return 1;', 'Start the project');
        $this->history->push('task');
        $this->history->inMirror('update-ref', 'refs/heads/main', $this->base);
        $this->history->commitFile('src/value.php', '<?php return 2;', 'Fix the value');
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    private function baseResult(): string
    {
        return $this->scriptResult([$this->base, 'task', '1', $this->linkVendor(), self::TEST, ...$this->testSteps()]);
    }

    private function headResult(string $head): string
    {
        return $this->scriptResult([$head, '-', '0', $this->linkVendor(), ...$this->testSteps()]);
    }

    private function linkVendor(): string
    {
        return 'ln -s '.escapeshellarg((string) realpath(self::VENDOR)).' vendor';
    }

    /**
     * @return list<string>
     */
    private function testSteps(): array
    {
        return [...new PhpGate()->testSteps(new Paths(self::TEST))];
    }

    /**
     * @param list<string> $arguments
     */
    private function scriptResult(array $arguments, string $branch = 'task'): string
    {
        $output = $this->history->folder.'/output-'.bin2hex(random_bytes(3));
        $work = $this->history->folder.'/work-'.bin2hex(random_bytes(3));
        new Filesystem()->mkdir([$output, $work]);
        $process = new Process(
            ['/bin/sh', self::SCRIPT, $this->history->mirror, $this->history->bundle('main..task'), $branch, ...$arguments],
            null,
            ['INTAKT_JOB_OUTPUT' => $output, 'TMPDIR' => $work],
        );
        $process->run();

        return (string) file_get_contents($output.'/regression-result');
    }
}
