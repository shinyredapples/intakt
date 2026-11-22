<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack\UseCase\CheckPullRequestText;

use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextVerdict;
use Intakt\Core\HandBack\Domain\CodeSpans;
use Intakt\Core\HandBack\Domain\EvidenceItems;
use Intakt\Core\HandBack\Domain\FencedBlock;
use Intakt\Core\HandBack\Domain\HiddenBlockOpeners;
use Intakt\Core\HandBack\Domain\MarkdownPart;
use Intakt\Core\HandBack\Domain\MarkdownText;
use Intakt\Core\HandBack\Domain\OpenListItems;
use Intakt\Core\HandBack\Domain\ParagraphEnd;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\CheckPullRequestTextCommand;
use Intakt\Core\HandBack\UseCase\CheckPullRequestText\CheckPullRequestTextInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CheckPullRequestTextCommand::class)]
#[CoversClass(CheckPullRequestTextInput::class)]
#[UsesClass(PullRequestTextRule::class)]
#[UsesClass(EvidenceItems::class)]
#[UsesClass(MarkdownText::class)]
#[UsesClass(MarkdownPart::class)]
#[UsesClass(CodeSpans::class)]
#[UsesClass(FencedBlock::class)]
#[UsesClass(HiddenBlockOpeners::class)]
#[UsesClass(OpenListItems::class)]
#[UsesClass(ParagraphEnd::class)]
#[UsesClass(PullRequestTextOutcome::class)]
#[UsesClass(PullRequestTextVerdict::class)]
final class CheckPullRequestTextCommandTest extends TestCase
{
    private const string TASK = "Build it.\n\nEvidence:\n- E1. A red check.\n- E2. A green check.\n";

    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-pr-text-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    public function testATextThatReferencesEveryItemPrintsPassed(): void
    {
        $tester = $this->executed(self::TASK, "feat: check it\n\n## Evidence\n- E1: red\n- E2: green\n");

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("passed: 2 Evidence items referenced, body of 33 characters (cap 4000)\n", $tester->getDisplay());
    }

    public function testATextMissingAnItemPrintsTheReasonAndTheItem(): void
    {
        $tester = $this->executed(self::TASK, "feat: check it\n\n- E1: red\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('failed: the pull request text does not reference every top-level Evidence item of the task', $this->flat($tester));
        self::assertStringContainsString('missing: E2', $tester->getDisplay());
    }

    public function testAReferenceInTheTitleIsNoReferenceInTheBody(): void
    {
        $tester = $this->executed(self::TASK, "feat: E1 and E2\n\nDone.\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('missing: E1, E2', $tester->getDisplay());
    }

    public function testABodyOverTheCapPrintsItsLength(): void
    {
        $tester = $this->executed('Fix it.', "fix: it\n\n".str_repeat('a', 4001));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('failed: the pull request body is over the cap', $this->flat($tester));
        self::assertStringContainsString('body: 4001 characters, cap 4000', $tester->getDisplay());
    }

    public function testAnUnreadableFileFailsNamingItsPath(): void
    {
        $tester = new CommandTester($this->application()->find('intakt:check:pull-request-text'));
        $tester->execute(['task' => $this->folder.'/missing.md', 'pull-request-text' => $this->folder.'/missing.md']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('missing.md', $this->flat($tester));
    }

    public function testTheSampleRepositorysTextsGiveAGreenAndARedResult(): void
    {
        $sample = dirname(__DIR__, 5).'/fixtures/sample-repository/pull-request-text';
        $tester = new CommandTester($this->application()->find('intakt:check:pull-request-text'));

        $green = $tester->execute(['task' => $sample.'/task.md', 'pull-request-text' => $sample.'/complete.md']);
        $red = $tester->execute(['task' => $sample.'/task.md', 'pull-request-text' => $sample.'/incomplete.md']);

        self::assertSame([Command::SUCCESS, Command::FAILURE], [$green, $red]);
        self::assertStringContainsString('missing: E2, E3', $tester->getDisplay());
    }

    private function executed(string $task, string $pullRequestText): CommandTester
    {
        new Filesystem()->dumpFile($this->folder.'/task.md', $task);
        new Filesystem()->dumpFile($this->folder.'/PR.md', $pullRequestText);
        $tester = new CommandTester($this->application()->find('intakt:check:pull-request-text'));
        $tester->execute(['task' => $this->folder.'/task.md', 'pull-request-text' => $this->folder.'/PR.md']);

        return $tester;
    }

    private function application(): Application
    {
        $application = new Application();
        $application->addCommand(new CheckPullRequestTextCommand(new Filesystem()));

        return $application;
    }

    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
