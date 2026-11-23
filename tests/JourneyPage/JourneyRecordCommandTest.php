<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class JourneyRecordCommandTest extends TestCase
{
    private const string COMMAND = __DIR__.'/../../bin/journey-record';
    private const string REPOSITORY = 'example/repo';
    private const string TOKEN = 'journey-record-marker-token';
    // Answers with the path it was asked for, so a test sees which file holds which resource.
    private const string ANSWERING_GH = "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$GH_LOG\"\nprintf '[[{\"path\":\"%s\"}]]\\n' \"\$2\"\n";

    private string $root = '';

    public function testTheJourneyRecordCommandIsExecutable(): void
    {
        self::assertTrue(is_executable(self::COMMAND));
    }

    public function testItWritesOneFileForEachResourceTheRecordedApiReadsAndTheStateFile(): void
    {
        $this->stubGh(self::ANSWERING_GH);

        $run = $this->runRecord();

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame($this->expectedFiles(), $this->recordedFiles());
    }

    public function testItAsksGhForEachResourceWithEveryPageSlurpedIntoOne(): void
    {
        $this->stubGh(self::ANSWERING_GH);

        $this->runRecord();

        $expected = array_map(static fn (JourneyResource $resource): string => 'api '.$resource->path(self::REPOSITORY).' --paginate --slurp', JourneyResource::cases());
        self::assertSame($expected, file($this->root.'/gh.log', FILE_IGNORE_NEW_LINES));
    }

    public function testTheRecordedFilesAreWhatRecordedApiReads(): void
    {
        $this->stubGh(self::ANSWERING_GH);

        $this->runRecord();

        self::assertSame('[[{"path":"'.JourneyResource::PullRequests->path(self::REPOSITORY).'"}]]'."\n", new RecordedApi($this->root.'/recorded')->pages(JourneyResource::PullRequests));
    }

    public function testTheStateFileHoldsTheNumberAndStateOfEveryRecordedIssue(): void
    {
        $this->stubGh("#!/bin/sh\nprintf '[[{\"number\":3,\"state\":\"open\",\"title\":\"a\"}],[{\"number\":4,\"state\":\"closed\"}]]\\n'\n");

        $this->runRecord();

        self::assertSame([3 => true, 4 => false], new IssueStates()->openByNumber($this->root.'/recorded'));
    }

    public function testAFailingStateDerivationKeepsTheRecordingAndLeavesNoStateFile(): void
    {
        $this->stubGh(self::ANSWERING_GH);
        file_put_contents($this->root.'/recorded/'.IssueStates::FILE, '[]');
        file_put_contents($this->root.'/stub/jq', "#!/bin/sh\nexit 1\n");
        chmod($this->root.'/stub/jq', 0o755);

        $run = $this->runRecord();

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('the highlights statuses stay as written', $run->getErrorOutput());
        self::assertSame(array_values(array_diff($this->expectedFiles(), [IssueStates::FILE])), $this->recordedFiles());
    }

    public function testAStateFilePathThatCannotBeReplacedKeepsTheRecording(): void
    {
        $this->stubGh(self::ANSWERING_GH);
        mkdir($this->root.'/recorded/'.IssueStates::FILE);

        $run = $this->runRecord();

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('cannot replace issue-states.json', $run->getErrorOutput());
        self::assertFileExists($this->root.'/recorded/issues.json');
        self::assertDirectoryExists($this->root.'/recorded/'.IssueStates::FILE);
    }

    public function testAGhThatFailsOnTheSecondResourceLeavesNoFileInTheFolder(): void
    {
        $this->stubGh("#!/bin/sh\ncase \"\$2\" in */issues\\?*) echo 'gh: HTTP 502' >&2; exit 1;; esac\nprintf '[[]]\\n'\n");

        $run = $this->runRecord();

        self::assertNotSame(0, $run->getExitCode());
        self::assertSame([], $this->recordedFiles());
    }

    public function testAFailedRunKeepsTheFilesOfAnEarlierRecording(): void
    {
        file_put_contents($this->root.'/recorded/issues.json', "[[]]\n");
        $this->stubGh("#!/bin/sh\nexit 1\n");

        $this->runRecord();

        self::assertSame(['issues.json'], $this->recordedFiles());
        self::assertSame("[[]]\n", file_get_contents($this->root.'/recorded/issues.json'));
    }

    public function testAnEmptyRepositoryNameStopsTheRunBeforeGhIsCalled(): void
    {
        $this->stubGh(self::ANSWERING_GH);

        $run = $this->runRecord('');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("GITHUB_REPOSITORY is empty\n", $run->getErrorOutput());
        self::assertFileDoesNotExist($this->root.'/gh.log');
        self::assertSame([], $this->recordedFiles());
    }

    public function testTheTokenIsNeverPrinted(): void
    {
        $this->stubGh("#!/bin/sh\nexit 1\n");

        $run = $this->runRecord();

        self::assertStringNotContainsString(self::TOKEN, $run->getOutput().$run->getErrorOutput());
    }

    public function testAMissingFolderArgumentIsAUsageError(): void
    {
        $run = new Process(['sh', self::COMMAND], $this->root, ['GITHUB_REPOSITORY' => self::REPOSITORY]);
        $run->run();

        self::assertSame(2, $run->getExitCode());
        self::assertSame("usage: bin/journey-record <folder>\n", $run->getErrorOutput());
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-journey-record-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir([$this->root.'/stub', $this->root.'/recorded']);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    private function stubGh(string $script): void
    {
        file_put_contents($this->root.'/stub/gh', $script);
        chmod($this->root.'/stub/gh', 0o755);
    }

    private function runRecord(string $repository = self::REPOSITORY): Process
    {
        $process = new Process(['sh', self::COMMAND, 'recorded'], $this->root, [
            'PATH' => $this->root.'/stub:'.getenv('PATH'),
            'GITHUB_REPOSITORY' => $repository,
            'GH_TOKEN' => self::TOKEN,
            'GH_LOG' => $this->root.'/gh.log',
        ]);
        $process->run();

        return $process;
    }

    /**
     * @return list<string>
     */
    private function expectedFiles(): array
    {
        $files = [...array_map(static fn (JourneyResource $resource): string => $resource->value.'.json', JourneyResource::cases()), IssueStates::FILE];
        sort($files);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function recordedFiles(): array
    {
        $names = scandir($this->root.'/recorded');

        return false === $names ? [] : array_values(array_diff($names, ['.', '..']));
    }
}
