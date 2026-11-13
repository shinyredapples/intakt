<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class JourneyCommandTest extends TestCase
{
    private const string COMMAND = __DIR__.'/../../bin/journey';
    private const string FIXTURES = __DIR__.'/Fixtures';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-journey-'.bin2hex(random_bytes(6));
        $files = new Filesystem();
        $files->mkdir($this->root.'/bin');
        $files->mkdir($this->root.'/docs/journey');
        $files->copy(self::COMMAND, $this->root.'/bin/journey');
        $files->symlink((string) realpath(__DIR__.'/../../vendor'), $this->root.'/vendor');
        $files->copy(self::FIXTURES.'/journey.md', $this->root.'/docs/journey.md');
        $files->copy(self::FIXTURES.'/usage.jsonl', $this->root.'/docs/journey/usage.jsonl');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testTheJourneyCommandIsExecutable(): void
    {
        self::assertTrue(is_executable(self::COMMAND));
    }

    public function testTheCommandWritesTheTablesFromTheRecordedAnswers(): void
    {
        $run = $this->runJourney();

        $page = (string) file_get_contents($this->root.'/docs/journey.md');
        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertStringContainsString('regenerated', $run->getOutput());
        self::assertStringContainsString('| Pull requests merged | 3 (to '.new NativeClock()->now()->format('Y-m-d').') |', $page);
        self::assertStringContainsString('| M0 Unblock | fixes the build loop needed first | 10-27 | 11-05 | 2 / 0 |', $page);
    }

    public function testTheCommandKeepsTheTextOutsideTheMarkers(): void
    {
        $this->runJourney();

        $page = (string) file_get_contents($this->root.'/docs/journey.md');
        self::assertStringContainsString("Hand-written intro.\n", $page);
        self::assertStringContainsString("Story between.\n\n<!-- journey:begin merges-per-day -->\n```\nuntouched chart\n```\n<!-- journey:end merges-per-day -->\n", $page);
        self::assertStringEndsWith("Lessons at the end.\n", $page);
    }

    public function testASecondRunWithTheSameInputsChangesNothing(): void
    {
        $this->runJourney();
        $first = (string) file_get_contents($this->root.'/docs/journey.md');

        $second = $this->runJourney();

        self::assertSame($first, (string) file_get_contents($this->root.'/docs/journey.md'));
        self::assertStringContainsString('is current', $second->getOutput());
    }

    public function testAPageWithoutMarkersIsLeftUnwritten(): void
    {
        file_put_contents($this->root.'/docs/journey.md', "No markers here.\n");

        $run = $this->runJourney();

        self::assertSame(1, $run->getExitCode());
        self::assertStringContainsString('begin and one end marker', $run->getErrorOutput());
        self::assertSame("No markers here.\n", file_get_contents($this->root.'/docs/journey.md'));
    }

    public function testAnUnknownOptionIsAUsageError(): void
    {
        $run = new Process([PHP_BINARY, 'bin/journey', '--bogus'], $this->root);
        $run->run();

        self::assertSame(2, $run->getExitCode());
        self::assertStringContainsString('usage:', $run->getErrorOutput());
    }

    private function runJourney(): Process
    {
        $process = new Process([PHP_BINARY, 'bin/journey', '--recorded='.realpath(self::FIXTURES.'/api')], $this->root);
        $process->run();

        return $process;
    }
}
