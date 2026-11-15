<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildStepNotRecorded;
use Intakt\Core\Build\UseCase\BuildTask\BuildTask;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskCommand;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskInput;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskOutcome;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Git\Contract\BuildCheckout;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BuildTaskCommand::class)]
#[UsesClass(BuildTaskInput::class)]
#[UsesClass(BuildTask::class)]
#[UsesClass(BuildTaskOutcome::class)]
#[UsesClass(BuildTaskRequest::class)]
#[UsesClass(BuildStepNotRecorded::class)]
#[UsesClass(BuildCheckoutFailed::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(Issues::class)]
final class BuildTaskCommandTest extends TestCase
{
    private const array OPTIONS = ['--remote' => 'remote', '--branch' => 'feat/task', '--directory' => 'clone'];

    public function testItPrintsEachPassedStepAndTheEscapedTitle(): void
    {
        $tester = $this->execute(new InMemoryLedger(), self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...self::OPTIONS]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("task passed\nmirror passed\nclone passed\ntitle Task 7", $tester->getDisplay());
    }

    public function testItReplacesEachControlCharacterOfTheTitleSoItCannotDriveTheTerminal(): void
    {
        $display = $this->displayOfTitle("Evil \e[2J\e]0;pwned\x07 <error>x</error> \x7F\u{80}\u{9B}31m\ttab\nline");

        self::assertStringContainsString("title Evil ?[2J?]0;pwned? <error>x</error> ???31m?tab?line\n", $display);
        self::assertDoesNotMatchRegularExpression('/[^\P{Cc}\n]/u', $display);
    }

    public function testItKeepsEveryOtherCharacterOfTheTitle(): void
    {
        $title = "Gr\u{FC}\u{DF}e \u{A0}\u{2713} ~ \u{1F600}";

        self::assertStringContainsString('title '.$title."\n", $this->displayOfTitle($title));
    }

    public function testItKeepsOnlyThePrintableAsciiOfATitleThatIsNotUtf8(): void
    {
        $display = $this->displayOfTitle("Evil \xFF\x9B31m \e[2J~");

        self::assertStringContainsString("title Evil ??31m ?[2J~\n", $display);
    }

    public function testItExitsWithFailureAtTheFailingStepAndRunsNoLaterOne(): void
    {
        $checkout = self::createStub(BuildCheckout::class);
        $checkout->method('updateMirror')->willThrowException(new BuildCheckoutFailed('git failed after 3 attempts'));
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, $checkout, ['project' => 'alpha', 'issue' => '7', ...self::OPTIONS]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('task passed', $tester->getDisplay());
        self::assertStringNotContainsString('mirror passed', $tester->getDisplay());
        self::assertStringContainsString('mirror failed: git failed after 3 attempts', $tester->getDisplay());
        self::assertSame(['task', 'mirror'], array_column($ledger->payloads(), 'step'));
    }

    public function testItReportsAStepTheLedgerDidNotRecord(): void
    {
        $tester = $this->execute(new InMemoryLedger(0), self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => '7', ...self::OPTIONS]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('did not record the task step', $tester->getDisplay());
    }

    #[DataProvider('refusedInputs')]
    public function testItRefusesABadInputWithoutRunningAStep(string $issue, string $option, string $message): void
    {
        $ledger = new InMemoryLedger();
        $options = array_filter(self::OPTIONS, static fn (string $name): bool => $name !== $option, ARRAY_FILTER_USE_KEY);

        $tester = $this->execute($ledger, self::createStub(BuildCheckout::class), ['project' => 'alpha', 'issue' => $issue, ...$options]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertSame(0, $ledger->count());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedInputs(): iterable
    {
        yield 'text' => ['seven', '', 'is not an issue number'];
        yield 'zero' => ['0', '', 'is not an issue number'];
        yield 'a number too long' => ['1234567890', '', 'is not an issue number'];
        yield 'no remote' => ['7', '--remote', 'The --remote option is required.'];
        yield 'no branch' => ['7', '--branch', 'The --branch option is required.'];
        yield 'no directory' => ['7', '--directory', 'The --directory option is required.'];
    }

    private function displayOfTitle(string $title): string
    {
        $listed = Issues::task(7);
        $task = new ListedIssue(7, $title, $listed->body, $listed->labels, $listed->author, $listed->updatedAt, $listed->milestoneDue, false);
        $build = new BuildTask($this->listing($task), self::createStub(BuildCheckout::class), new InMemoryLedger());

        return $this->tester($build, ['project' => 'alpha', 'issue' => '7', ...self::OPTIONS])->getDisplay();
    }

    /**
     * @param array<string, string> $input
     */
    private function execute(InMemoryLedger $ledger, BuildCheckout $checkout, array $input): CommandTester
    {
        return $this->tester(new BuildTask($this->listing(Issues::task(7)), $checkout, $ledger), $input);
    }

    private function listing(ListedIssue $task): IssueReads
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('listOpen')->willReturn(IssueListing::listed([$task], null));

        return $reads;
    }

    /**
     * @param array<string, string> $input
     */
    private function tester(BuildTask $build, array $input): CommandTester
    {
        $application = new Application();
        $application->addCommand(new BuildTaskCommand($build));
        $tester = new CommandTester($application->find('intakt:build:task'));
        $tester->execute($input);

        return $tester;
    }
}
