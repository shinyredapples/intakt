<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\CheckCommand;
use Intakt\Gates\JourneysGate;
use Intakt\Gates\Options;
use Intakt\Gates\ProcessRunner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(JourneysGate::class)]
#[UsesClass(CheckCommand::class)]
#[UsesClass(Options::class)]
#[UsesClass(ProcessRunner::class)]
final class JourneysGateTest extends SandboxTestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testAPassingJourneyPassesTheGate(): void
    {
        $run = $this->sandboxWith('journeys-passing')->check('--journeys');

        self::assertSame(0, $run->exitCode(), $run->output());
        self::assertTrue($run->passed('journeys'), $run->output());
    }

    public function testABrokenStepFailsOnlyItsJourneyAndNamesTheStep(): void
    {
        $run = $this->sandboxWith('journeys-one-broken')->check('--journeys');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertTrue($run->failed('journeys'), $run->output());
        self::assertStringContainsString('BrokenJourneyTest.php: journey "broken" failed at step "read the second page": Failed asserting that two strings are identical.', $run->findingsOf('journeys'));
        self::assertStringContainsString('NoticeJourneyTest.php', $run->findingsOf('journeys'));
        self::assertStringNotContainsString('CalmJourneyTest', $run->findingsOf('journeys'));
    }

    public function testAChildAJourneyLeavesBehindDoesNotOutliveTheGate(): void
    {
        if (ProcessRunner::PID_NAMESPACE !== new ProcessRunner()->containment()) {
            self::markTestSkipped('This host refuses unprivileged user namespaces.');
        }

        $sandbox = $this->sandboxWith('journeys-leaving-a-child');
        $marker = $sandbox->root.'/child-started';
        $seconds = '50.'.random_int(100000, 999999);
        $journey = $sandbox->root.'/tests/Journeys/ChildJourneyTest.php';
        $sandbox->write('tests/Journeys/ChildJourneyTest.php', strtr((string) file_get_contents($journey), ['MARKER' => $marker, 'SLEEP_SECONDS' => $seconds]));

        $run = $sandbox->check('--journeys');

        self::assertTrue($run->passed('journeys'), $run->output());
        self::assertFileExists($marker, 'The journey never started its child');
        self::assertFalse($this->isSleeping($seconds), 'The child of the journey survived the gate');
    }

    public function testTheApplicationSuiteLeavesOutOnlyTheJourneys(): void
    {
        $sandbox = $this->sandboxWith('journeys-suite-selection');
        $sandbox->write('phpunit.dist.xml', (string) file_get_contents(self::ROOT.'/phpunit.dist.xml'));

        $listed = $sandbox->run(PHP_BINARY, self::ROOT.'/vendor/bin/phpunit', '--configuration='.$sandbox->root.'/phpunit.dist.xml', '--bootstrap='.self::ROOT.'/vendor/autoload.php', '--list-test-files');

        self::assertStringNotContainsString('WalkJourneyTest.php', $listed->output, $listed->output.$listed->errors);
        self::assertStringContainsString('tests/Journeys/Support/HarnessTest.php', $listed->output, $listed->output.$listed->errors);
        self::assertStringContainsString('tests/Elsewhere/TaggedTest.php', $listed->output, $listed->output.$listed->errors);
    }

    public function testATreeWithoutJourneysFailsTheGate(): void
    {
        $run = $this->emptySandbox()->check('--journeys');

        self::assertSame(1, $run->exitCode(), $run->output());
        self::assertStringContainsString('no journey found', $run->findingsOf('journeys'));
    }

    /**
     * @param list<string> $others
     */
    #[DataProvider('optionsThatJourneysRefuse')]
    public function testJourneysDoNotRunTogetherWithOtherOptions(array $others): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--journeys runs alone');

        Options::fromArguments(['--journeys', ...$others]);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function optionsThatJourneysRefuse(): iterable
    {
        yield 'fast' => [['--fast']];
        yield 'base' => [['--base=HEAD']];
        yield 'test merge' => [['--test-merge=HEAD:HEAD']];
        yield 'only' => [['--only=tests']];
        yield 'skip' => [['--skip=tests']];
    }

    public function testTheJourneysGateIsNotOneOfTheGatesAChangePasses(): void
    {
        $run = $this->emptySandbox()->check('--only=journeys');

        self::assertSame(2, $run->exitCode());
        self::assertStringContainsString('unknown gate journeys', $run->output());
    }

    private function isSleeping(string $seconds): bool
    {
        $commandLines = glob('/proc/[0-9]*/cmdline');

        return array_any(false === $commandLines ? [] : $commandLines, fn (string $file) => ['sleep', $seconds] === explode("\0", rtrim((string) @file_get_contents($file), "\0")));
    }
}
