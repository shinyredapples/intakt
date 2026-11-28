<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Contract\Decisions;
use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\Domain\QuestionRefused;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\Build\UseCase\RunBuildJob\RecordedBuildStops;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJobCommand;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJobInput;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FencedText;
use Intakt\Core\Review\Contract\PromptAssembly;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\PromptPart;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use Intakt\Tests\Core\Build\ScriptedJobRunner;
use Intakt\Tests\Core\HandBack\BuildHistory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The build script and the use case in one run: the script's own output folder is what the use case reads, so nothing between them is written by the test.
#[CoversClass(RunBuildJob::class)]
#[UsesClass(RecordedBuildStops::class)]
#[UsesClass(BuildLimits::class)]
#[CoversClass(BuildJobOutcome::class)]
#[CoversClass(BuildQuestion::class)]
#[UsesClass(RunBuildJobCommand::class)]
#[UsesClass(RunBuildJobInput::class)]
#[UsesClass(QuestionRefused::class)]
#[UsesClass(PromptAssembly::class)]
#[UsesClass(PromptPart::class)]
#[UsesClass(FencedText::class)]
#[UsesClass(BuilderPrompt::class)]
#[UsesClass(GatesAtOnce::class)]
#[UsesClass(GatesByDefault::class)]
#[UsesClass(BuildJob::class)]
#[UsesClass(BuildVerdict::class)]
#[UsesClass(BuildBrief::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Fence::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class ScriptedQuestionTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/build.sh';

    private BuildHistory $history;
    private string $output = '';

    public function testAScriptedQuestionReachesTheBuildJobsOutcome(): void
    {
        $this->givenTheBuildScriptRanWithTheCli(<<<'SH'
            printf '{"question":"Which store should the ledger use?","choices":["SQLite","PostgreSQL"],"recommendation":"SQLite"}' > QUESTION.json
            SH);

        $outcome = $this->whenTheBuildJobReadsTheScriptsOutput();

        self::assertSame(BuildVerdict::Asked, $outcome->verdict);
        self::assertSame(
            ['Which store should the ledger use?', ['SQLite', 'PostgreSQL'], 'SQLite'],
            [$outcome->question?->question, $outcome->question?->choices, $outcome->question?->recommendation],
        );
    }

    public function testAQuestionTheCheckoutAlreadyHeldWithConvertedLineEndingsEndsAsNoCommitsWhenTheCliDoesNothing(): void
    {
        $this->history->commitFile('.gitattributes', "QUESTION.json text eol=crlf\n", 'chore: check the question out with other line endings');
        $this->history->commitFile('QUESTION.json', "{\"question\":\"Old?\",\"choices\":[\"a\"],\"recommendation\":\"a\"}\n", 'docs: keep an old question');
        $this->givenTheBuildScriptRanWithTheCli('true');

        $outcome = $this->whenTheBuildJobReadsTheScriptsOutput();

        self::assertSame(BuildVerdict::NoCommits, $outcome->verdict);
        self::assertNull($outcome->question);
    }

    public function testTheBuildCommandPrintsAScriptedQuestion(): void
    {
        $this->givenTheBuildScriptRanWithTheCli(<<<'SH'
            printf '{"question":"Which store should the ledger use?","choices":["SQLite","PostgreSQL"],"recommendation":"SQLite"}' > QUESTION.json
            SH);

        $tester = $this->whenTheBuildCommandReadsTheScriptsOutput();

        self::assertSame(
            [Command::SUCCESS, "question: Which store should the ledger use?\nchoice: SQLite\nchoice: PostgreSQL\nrecommendation: SQLite\n"],
            [$tester->getStatusCode(), $tester->getDisplay()],
        );
    }

    public function testTheBuildCommandRefusesAScriptedQuestionWithTwoChoicesTheSameOnceTrimmed(): void
    {
        $this->givenTheBuildScriptRanWithTheCli(<<<'SH'
            printf '{"question":"Keep it?","choices":["Keep"," Keep "],"recommendation":"Keep"}' > QUESTION.json
            SH);

        $tester = $this->whenTheBuildCommandReadsTheScriptsOutput();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString("question refused: two choices are the same\n", $tester->getDisplay());
        self::assertStringContainsString('failed: '.BuildVerdict::QuestionRefused->reason(), (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    public function testTheBuildCommandPrintsAnInvisibleCharacterOfAScriptedQuestionAsItsCodePoint(): void
    {
        $this->givenTheBuildScriptRanWithTheCli(<<<'SH'
            printf '{"question":"Which\\u034f store?","choices":["SQLite\\u034f","SQLite"],"recommendation":"SQLite"}' > QUESTION.json
            SH);

        $tester = $this->whenTheBuildCommandReadsTheScriptsOutput();

        self::assertSame("question: Which\\u{34F} store?\nchoice: SQLite\\u{34F}\nchoice: SQLite\nrecommendation: SQLite\n", $tester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->history = new BuildHistory();
        $this->output = $this->history->folder.'/output';
        new Filesystem()->mkdir([$this->output, $this->history->folder.'/job', $this->history->folder.'/bin']);
        file_put_contents($this->history->folder.'/prompt.md', "Build the task.\n");
    }

    protected function tearDown(): void
    {
        $this->history->remove();
    }

    private function givenTheBuildScriptRanWithTheCli(string $script): void
    {
        $folder = $this->history->folder;
        file_put_contents($folder.'/bin/claude', "#!/bin/sh\n".$script."\n");
        chmod($folder.'/bin/claude', 0o755);
        // As the container sets them, and without the host's git variables, which would point git at another repository.
        $environment = ['HOME' => $folder.'/job', 'TMPDIR' => $folder.'/job', 'INTAKT_JOB_OUTPUT' => $this->output, 'PATH' => $folder.'/bin:'.getenv('PATH'), 'INTAKT_GATE_SLOTS' => false];
        foreach (array_keys([...getenv(), ...$_ENV]) as $name) {
            if (is_string($name) && str_starts_with($name, 'GIT_')) {
                $environment[$name] = false;
            }
        }

        new Process(['/bin/sh', self::SCRIPT, $this->history->work, $folder.'/prompt.md', 'task', 'claude-sonnet-5-5', '7', '600', '', '1500'], null, $environment, timeout: 20)->mustRun();
    }

    private function whenTheBuildJobReadsTheScriptsOutput(): BuildJobOutcome
    {
        return $this->builds()->run(new BuildJob('demo', $this->history->work, 'task', new BuildBrief('task', new Decisions(), null), Tier::Standard, 'intakt-job-demo:1'));
    }

    private function whenTheBuildCommandReadsTheScriptsOutput(): CommandTester
    {
        file_put_contents($this->history->folder.'/task.md', "task\n");
        $application = new Application();
        $application->addCommand(new RunBuildJobCommand($this->builds(), new Filesystem()));
        $tester = new CommandTester($application->find('intakt:build:run'));
        $tester->execute(['project' => 'demo', 'clone' => $this->history->work, 'branch' => 'task', 'task' => $this->history->folder.'/task.md', 'image' => 'intakt-job-demo:1', 'bundle' => $this->history->folder.'/out.bundle']);

        return $tester;
    }

    private function builds(): RunBuildJob
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::ClaudeCode, 'claude-sonnet-5-5'));

        return new RunBuildJob($tiers, new BuilderPrompt(new Fence(), new PromptCut(new Fence())), new ScriptedJobRunner($this->output), new PhpGate(), new GatesAtOnce(12, 1), 1500, new Filesystem(), self::SCRIPT, $this->history->folder.'/prompts', new RecordedBuildStops(), new BuildLimits(1, 1, 1, 1));
    }
}
