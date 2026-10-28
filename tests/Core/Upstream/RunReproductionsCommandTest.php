<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Upstream;

use Intakt\Core\Upstream\Contract\ReproductionOutcome;
use Intakt\Core\Upstream\Contract\ReproductionStatus;
use Intakt\Core\Upstream\Infrastructure\PhpunitReproductionRunner;
use Intakt\Core\Upstream\UseCase\RunReproductions\RunReproductionsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunReproductionsCommand::class)]
#[CoversClass(PhpunitReproductionRunner::class)]
#[CoversClass(ReproductionOutcome::class)]
#[CoversClass(ReproductionStatus::class)]
final class RunReproductionsCommandTest extends TestCase
{
    private const string ROOT = __DIR__.'/../../..';

    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-upstream-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

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
        $this->givenReproduction('open-bug', '$this->assertSame(1, 2);');

        $quiet = $this->whenTheCommandRuns();
        $verbose = $this->whenTheCommandRuns(['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertStringNotContainsString('Failed asserting', $quiet->getDisplay());
        self::assertStringContainsString('Failed asserting', $verbose->getDisplay());
    }

    public function testThePinnedSymfonyAiVersionsAreListed(): void
    {
        $display = $this->whenTheCommandRuns()->getDisplay();

        self::assertMatchesRegularExpression('/^symfony\/ai-platform \S+$/m', $display);
    }

    private function givenReproduction(string $slug, string $body): void
    {
        $class = 'Repro'.bin2hex(random_bytes(4)).'Test';
        $source = "<?php\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class {$class} extends TestCase\n{\n    public function testIt(): void\n    {\n        {$body}\n    }\n}\n";

        new Filesystem()->dumpFile($this->folder.'/'.$slug.'/'.$class.'.php', $source);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function whenTheCommandRuns(array $options = []): CommandTester
    {
        $application = new Application();
        $application->addCommand(new RunReproductionsCommand(new PhpunitReproductionRunner(self::ROOT), self::ROOT));
        $tester = new CommandTester($application->find('intakt:upstream:run'));
        $tester->execute(['directory' => $this->folder], $options);

        return $tester;
    }
}
