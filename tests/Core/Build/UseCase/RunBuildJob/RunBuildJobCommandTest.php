<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Domain\BuilderPrompt;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\GatesAtOnce;
use Intakt\Core\Build\Domain\GatesByDefault;
use Intakt\Core\Build\Domain\QuestionRefused;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildCloneIsNoFolder;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobOutcome;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildVerdict;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJobCommand;
use Intakt\Core\Build\UseCase\RunBuildJob\RunBuildJobInput;
use Intakt\Core\Build\UseCase\RunBuildJob\TierNotKnown;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEnd;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use IntlChar;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RunBuildJobCommand::class)]
#[CoversClass(RunBuildJobInput::class)]
#[CoversClass(TierNotKnown::class)]
#[UsesClass(BuildCloneIsNoFolder::class)]
#[UsesClass(JobRefused::class)]
#[UsesClass(RunBuildJob::class)]
#[UsesClass(BuildJob::class)]
#[UsesClass(BuildJobOutcome::class)]
#[UsesClass(BuildVerdict::class)]
#[UsesClass(BuilderNotOnClaudeCode::class)]
#[UsesClass(BuildBrief::class)]
#[UsesClass(BuilderPrompt::class)]
#[UsesClass(BuildQuestion::class)]
#[UsesClass(QuestionRefused::class)]
#[UsesClass(GatesAtOnce::class)]
#[UsesClass(GatesByDefault::class)]
#[UsesClass(Fence::class)]
#[UsesClass(PromptCut::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierNotMapped::class)]
#[UsesClass(PhpGate::class)]
#[UsesClass(JobSpec::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobInput::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobResult::class)]
#[UsesClass(JobEnd::class)]
#[UsesClass(JobOutput::class)]
final class RunBuildJobCommandTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../../../docker/job/build.sh';

    private string $folder = '';

    private string $promptSeen = '';

    private ?JobSpec $ran = null;

    private ?JobRunner $runner = null;

    public function testABuiltJobWritesTheBundleAndPrintsThePullRequestText(): void
    {
        $tester = $this->executed(new JobOutput(['handback.bundle' => 'BUNDLE', 'pull-request.md' => "feat: <b>x</b>\n\n## Summary\n"]), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('BUNDLE', file_get_contents($this->folder.'/out.bundle'));
        self::assertSame('bundle '.$this->folder."/out.bundle\npull request text:\nfeat: <b>x</b>\n\n## Summary\n", $tester->getDisplay());
    }

    public function testTheTaskFileAndEachDecisionReachThePromptFenced(): void
    {
        $this->executed(new JobOutput([]), ['--decision' => ['PLANT-first', 'PLANT-second']]);

        self::assertStringContainsString(new Fence()->wrap(UntrustedSource::Task, 'PLANT-task')->rendered(), $this->promptSeen);
        self::assertStringContainsString(new Fence()->wrap(UntrustedSource::Decisions, "D1: PLANT-first\nD2: PLANT-second")->rendered(), $this->promptSeen);
    }

    public function testTheTierOptionPicksTheModel(): void
    {
        $this->executed(new JobOutput([]), ['--tier' => 'strong']);

        self::assertSame('claude-strong', ($this->ran->command ?? [])[7] ?? null);
    }

    public function testAJobThatIsNotBuiltPrintsTheReasonAndWritesNoBundle(): void
    {
        $tester = $this->executed(new JobOutput(['build.log' => "claude exited with 1\n"]), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringStartsWith("claude exited with 1\n", $tester->getDisplay());
        self::assertStringContainsString('failed: '.BuildVerdict::NoCommits->reason(), $this->flat($tester));
        self::assertFileDoesNotExist($this->folder.'/out.bundle');
    }

    public function testAnAskedJobPrintsTheQuestionItsChoicesAndItsRecommendationAndWritesNoBundle(): void
    {
        $tester = $this->executed(new JobOutput(['question.json' => '{"question":"Which <b>store</b>?","choices":["SQLite","Postgres"],"recommendation":"SQLite"}']), []);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame("question: Which <b>store</b>?\nchoice: SQLite\nchoice: Postgres\nrecommendation: SQLite\n", $tester->getDisplay());
        self::assertFileDoesNotExist($this->folder.'/out.bundle');
    }

    public function testALineBreakOrATerminalControlInTheQuestionCannotForgeALineOfItsOwn(): void
    {
        $question = '{"question":"Which store?\nrecommendation: Postgres\u001b[2K ","choices":["SQLite\r","Postgres"],"recommendation":"Postgres"}';

        $tester = $this->executed(new JobOutput(['question.json' => $question]), []);

        self::assertSame("question: Which store?\\u{A}recommendation: Postgres\\u{1B}[2K\\u{2028}\nchoice: SQLite\nchoice: Postgres\nrecommendation: Postgres\n", $tester->getDisplay());
    }

    public function testChoicesThatDifferOnlyInACharacterThatIsNotPrintedAsItIsStayDifferentSoTheRecommendedOneCanBeTold(): void
    {
        $question = '{"question":"Which?","choices":["a\tb","a\u000bb","a?b","a\\\\u{9}b","a​b","a b","a b","ab\\\\"],"recommendation":"a\u000bb"}';

        $tester = $this->executed(new JobOutput(['question.json' => $question]), []);

        $lines = explode("\n", trim($tester->getDisplay()));
        self::assertSame(
            ['question: Which?', 'choice: a\u{9}b', 'choice: a\u{B}b', 'choice: a?b', 'choice: a\\\\u{9}b', 'choice: a\u{200B}b', 'choice: a\u{A0}b', 'choice: a b', 'choice: ab\\\\', 'recommendation: a\u{B}b'],
            $lines,
        );
        self::assertCount(count($lines), array_unique($lines));
    }

    public function testADefaultIgnorableCharacterOutsideTheControlsIsPrintedAsItsCodePoint(): void
    {
        $question = json_encode(['question' => "Which\u{34F}?", 'choices' => ["a\u{FE0E}b", "a\u{115F}b", "a\u{3164}b", "a\u{FFA0}b", 'ab'], 'recommendation' => 'ab'], JSON_THROW_ON_ERROR);

        $tester = $this->executed(new JobOutput(['question.json' => $question]), []);

        self::assertSame(
            "question: Which\\u{34F}?\nchoice: a\\u{FE0E}b\nchoice: a\\u{115F}b\nchoice: a\\u{3164}b\nchoice: a\\u{FFA0}b\nchoice: ab\nrecommendation: ab\n",
            $tester->getDisplay(),
        );
    }

    public function testVisibleLettersOutsideAsciiArePrintedAsTheyAre(): void
    {
        $tester = $this->executed(new JobOutput(['question.json' => '{"question":"Café or 한글?","choices":["é","한"],"recommendation":"é"}']), []);

        self::assertSame("question: Café or 한글?\nchoice: é\nchoice: 한\nrecommendation: é\n", $tester->getDisplay());
    }

    public function testTheDefaultIgnorableRangesAreExactlyThoseOfUnicodesDataAsIntlKnowsThem(): void
    {
        $listed = [];
        foreach (RunBuildJobCommand::DEFAULT_IGNORABLE as [$first, $last]) {
            array_push($listed, ...range($first, $last));
        }
        $known = array_values(array_filter(range(0, 0x10FFFF), static fn (int $point): bool => true === IntlChar::hasBinaryProperty($point, IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT)));

        self::assertSame($known, $listed);
    }

    public function testARefusedQuestionIsPrintedAsAFailedBuildWithItsReason(): void
    {
        $tester = $this->executed(new JobOutput(['question.json' => 'not json']), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringStartsWith("question refused: the question is not a JSON object\n", $tester->getDisplay());
        self::assertStringContainsString('failed: '.BuildVerdict::QuestionRefused->reason(), $this->flat($tester));
    }

    public function testAnUnknownTierIsPrintedAndFails(): void
    {
        $tester = $this->executed(new JobOutput([]), ['--tier' => 'huge']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The tier "huge" is not one of strong, standard, light.', $this->flat($tester));
        self::assertNull($this->ran);
    }

    public function testATierTheProjectDoesNotMapIsPrintedAndFails(): void
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willThrowException(new TierNotMapped('demo', Tier::Standard));

        $tester = $this->executed(new JobOutput([]), [], $tiers);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Project "demo" maps no model to the "standard" tier.', $this->flat($tester));
    }

    public function testATierOnAnotherProviderIsPrintedAndFails(): void
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturn(new ResolvedModel(Provider::Codex, 'gpt'));

        $tester = $this->executed(new JobOutput([]), [], $tiers);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('needs provider "claude_code"', $this->flat($tester));
    }

    public function testATaskFileThatCannotBeReadIsPrintedAndFails(): void
    {
        $tester = $this->executed(new JobOutput([]), ['task' => $this->folder.'/missing.md']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('missing.md', $this->flat($tester));
        self::assertNull($this->ran);
    }

    public function testACloneThatIsNoFolderIsPrintedAndFails(): void
    {
        $tester = $this->executed(new JobOutput([]), ['clone' => $this->folder.'/missing']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('missing" is no folder.', $this->flat($tester));
        self::assertNull($this->ran);
    }

    public function testAJobTheRunnerRefusesIsPrintedAndFails(): void
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willThrowException(JobRefused::alreadyRunning(new JobId('build-demo')));
        $this->runner = $runner;

        $tester = $this->executed(new JobOutput([]), []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The job "build-demo" is already running.', $this->flat($tester));
    }

    public function testAnUnexpectedInvalidArgumentExceptionPassesThroughTheCommand(): void
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willThrowException(new InvalidArgumentException('a bug in the tiers'));

        $this->expectExceptionObject(new InvalidArgumentException('a bug in the tiers'));

        $this->executed(new JobOutput([]), [], $tiers);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-build-command-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->folder.'/clone');
        file_put_contents($this->folder.'/task.md', 'PLANT-task');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function executed(JobOutput $output, array $arguments, ?TierResolver $tiers = null): CommandTester
    {
        $runner = self::createStub(JobRunner::class);
        $runner->method('run')->willReturnCallback(function (JobSpec $spec) use ($output): JobResult {
            $this->ran = $spec;
            $this->promptSeen = (string) file_get_contents($spec->inputs[1]->hostPath);

            return new JobResult(new JobEnd(JobCause::Exited, 0), $output);
        });

        $application = new Application();
        $application->addCommand(new RunBuildJobCommand(new RunBuildJob(
            $tiers ?? $this->tiers(),
            new BuilderPrompt(new Fence(), new PromptCut(new Fence())),
            $this->runner ?? $runner,
            new PhpGate(),
            new GatesAtOnce(12, 1),
            1500,
            new Filesystem(),
            self::SCRIPT,
            $this->folder.'/prompts',
        ), new Filesystem()));
        $tester = new CommandTester($application->find('intakt:build:run'));
        $tester->execute([
            'project' => 'demo',
            'clone' => $this->folder.'/clone',
            'branch' => 'task',
            'task' => $this->folder.'/task.md',
            'image' => 'intakt-job-demo:1',
            'bundle' => $this->folder.'/out.bundle',
            ...$arguments,
        ]);

        return $tester;
    }

    private function tiers(): TierResolver
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturnCallback(
            static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel(Provider::ClaudeCode, 'claude-'.$tier->value),
        );

        return $tiers;
    }

    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
