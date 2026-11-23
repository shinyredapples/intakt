<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Upstream;

use Intakt\Core\Upstream\Contract\ReproductionOutcome;
use Intakt\Core\Upstream\Contract\ReproductionStatus;
use Intakt\Core\Upstream\Infrastructure\ReproductionRunner;
use Intakt\Core\Upstream\UseCase\RunReproductions\RunReproductionsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunReproductionsCommand::class)]
#[CoversClass(ReproductionRunner::class)]
#[CoversClass(ReproductionOutcome::class)]
#[CoversClass(ReproductionStatus::class)]
final class RunReproductionsCommandTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $folder = '';

    public function testAPassingAndAFailingReproductionAreReportedApart(): void
    {
        $this->givenReproduction('fixed-bug', '$this->assertTrue(true);');
        $this->givenReproduction('open-bug', '$this->assertSame(1, 2);');

        $tester = $this->whenTheCommandRuns();

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^passes\s+fixed-bug$/m', $display);
        self::assertMatchesRegularExpression('/^still fails\s+open-bug$/m', $display);
        self::assertStringContainsString('2 reproductions, 1 still fail', $display);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testTheRunSucceedsWhenEveryReproductionPasses(): void
    {
        $this->givenReproduction('fixed-bug', '$this->assertTrue(true);');

        $tester = $this->whenTheCommandRuns();

        self::assertStringContainsString('1 reproductions, 0 still fail', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testTheFailureOutputIsShownOnlyWhenVerbose(): void
    {
        $this->givenScript('open-script', "echo 'still broken';");

        $quiet = $this->whenTheCommandRuns();
        $verbose = $this->whenTheCommandRuns(['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertStringNotContainsString('still broken', $quiet->getDisplay());
        self::assertStringContainsString('still broken', $verbose->getDisplay());
    }

    public function testThePinnedSymfonyAiVersionsAreListed(): void
    {
        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^symfony\/ai-platform \S+$/m', $display);
    }

    public function testAMissingFolderFailsTheRun(): void
    {
        $tester = $this->whenTheCommandRuns(directory: $this->folder.'/missing');

        self::assertStringContainsString('No readable reproduction folder', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testAnUnreadableFolderFailsTheRun(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('This user ignores file permissions (superuser).');
        }
        $this->givenReproduction('open-bug', '$this->assertSame(1, 2);');
        new Filesystem()->chmod($this->folder, 0o000);

        $tester = $this->whenTheCommandRuns();

        self::assertStringContainsString('No readable reproduction folder', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testAFolderThatCannotBeSearchedFailsTheRun(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('This user ignores file permissions (superuser).');
        }
        $this->givenReproduction('open-bug', '$this->assertSame(1, 2);');
        new Filesystem()->chmod($this->folder, 0o444);

        $tester = $this->whenTheCommandRuns();

        self::assertStringContainsString('Could not list the reproductions', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    public function testTheFailureOutputIsShownWithoutInterpretingStyleTags(): void
    {
        $this->givenScript('open-script', "echo '<error>boom</error>';");

        $display = $this->whenTheCommandRuns(['verbosity' => OutputInterface::VERBOSITY_VERBOSE])->getDisplay();

        self::assertStringContainsString('<error>boom</error>', $display);
    }

    public function testAFolderWithGlobCharactersInItsPathIsSearchedLiterally(): void
    {
        $this->givenScript('samples[1]/open-script', "echo 'ok';");

        $tester = $this->whenTheCommandRuns(directory: $this->folder.'/samples[1]');

        self::assertMatchesRegularExpression('/^still fails\s+open-script$/m', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testAFolderWithoutATestOrScriptStillFails(): void
    {
        new Filesystem()->dumpFile($this->folder.'/notes-only/REPORT.md', "# Report\n");

        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^still fails\s+notes-only$/m', $display);
    }

    public function testAScriptThatPrintsTheMarkerAndExitsWithZeroPasses(): void
    {
        $this->givenScript('fixed-script', "echo 'checked'.PHP_EOL.'REPRODUCTION PASSES'.PHP_EOL;");

        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^passes\s+fixed-script$/m', $display);
    }

    public function testAScriptThatExitsWithAnErrorStillFails(): void
    {
        $this->givenScript('open-script', "echo 'REPRODUCTION PASSES'.PHP_EOL;\nexit(1);");

        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^still fails\s+open-script$/m', $display);
    }

    public function testAScriptWithoutTheMarkerStillFails(): void
    {
        $this->givenScript('unchecked-script', "echo 'ok';");

        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^still fails\s+unchecked-script$/m', $display);
    }

    public function testAScriptCanUseTheProjectAutoloader(): void
    {
        $this->givenScript('autoloaded-script', "if (class_exists(\\Symfony\\Component\\Process\\Process::class)) {\n    echo 'REPRODUCTION PASSES';\n}");

        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^passes\s+autoloaded-script$/m', $display);
    }

    public function testAHangingScriptStillFailsWithItsFractionalTimeoutAndTheRunGoesOn(): void
    {
        $this->givenScript('a-hanging-script', 'sleep(30);');
        $this->givenScript('b-fixed-script', "echo 'REPRODUCTION PASSES';");

        $display = $this->whenTheCommandRuns(['verbosity' => OutputInterface::VERBOSITY_VERBOSE], timeout: 0.5)->getDisplay();

        self::assertMatchesRegularExpression('/^still fails\s+a-hanging-script$/m', $display);
        self::assertStringContainsString('Timed out after 0.5 seconds.', $display);
        self::assertMatchesRegularExpression('/^passes\s+b-fixed-script$/m', $display);
    }

    public function testASkippedReproductionStillFails(): void
    {
        $this->givenReproduction('skipped-bug', '$this->markTestSkipped();');

        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^still fails\s+skipped-bug$/m', $display);
    }

    public function testAnIncompleteReproductionStillFails(): void
    {
        $this->givenReproduction('incomplete-bug', '$this->markTestIncomplete();');

        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^still fails\s+incomplete-bug$/m', $display);
    }

    public function testAReproductionWithoutAnAssertionStillFails(): void
    {
        $this->givenReproduction('empty-bug', '');

        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^still fails\s+empty-bug$/m', $display);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-upstream-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        // The unreadable and unsearchable folder tests take the mode away, and remove() cannot list a folder without it.
        new Filesystem()->chmod($this->folder, 0o755);
        new Filesystem()->remove($this->folder);
    }

    private function givenReproduction(string $slug, string $body): void
    {
        $class = 'Repro'.bin2hex(random_bytes(4)).'Test';
        $source = "<?php\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {$class} extends TestCase\n{\n    public function testIt(): void\n    {\n        {$body}\n    }\n}\n";

        new Filesystem()->dumpFile($this->folder.'/'.$slug.'/'.$class.'.php', $source);
    }

    private function givenScript(string $slug, string $body): void
    {
        new Filesystem()->dumpFile($this->folder.'/'.$slug.'/reproduce.php', "<?php\n\n{$body}\n");
    }

    /**
     * @param array<string, mixed> $options
     */
    private function whenTheCommandRuns(array $options = [], ?string $directory = null, float $timeout = 300.0): CommandTester
    {
        $application = new Application();
        $application->addCommand(new RunReproductionsCommand(new ReproductionRunner(self::ROOT, $timeout), self::ROOT));
        $tester = new CommandTester($application->find('intakt:upstream:run'));
        $tester->execute(['directory' => $directory ?? $this->folder], $options);

        return $tester;
    }
}
