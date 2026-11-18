<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Uri\Rfc3986\Uri;

#[CoversClass(JourneyAlarmTask::class)]
#[UsesClass(JourneyRun::class)]
#[UsesClass(JourneyFailure::class)]
final class JourneyAlarmTaskTest extends TestCase
{
    private const string LINK = 'https://github.com/example/repo/actions/runs/42';

    public function testOneFailingJourneyIsNamedInAConventionalTitle(): void
    {
        self::assertSame('fix(journeys): journey "broken" fails on main', $this->oneBroken()->title());
    }

    public function testSeveralFailingJourneysAreCountedInTheTitle(): void
    {
        $task = $this->taskFor('A.php: journey "a" failed at step "one": x', 'B.php: journey "b" failed at step "two": y');

        self::assertSame('fix(journeys): 2 journeys fail on main', $task->title());
    }

    public function testTwoFailingStepsOfOneJourneyCountAsOneJourney(): void
    {
        $task = $this->taskFor('A.php: journey "a" failed at step "one": x', 'A.php: journey "a" failed at step "two": y');

        self::assertSame('fix(journeys): journey "a" fails on main', $task->title());
    }

    public function testALongJourneyNameIsCutSoTheTitleStaysACommitSubject(): void
    {
        $task = $this->taskFor('A.php: journey "'.str_repeat('long ', 40).'" failed at step "one": x');

        self::assertLessThanOrEqual(100, mb_strlen($task->title()));
        self::assertStringEndsWith('..." fails on main', $task->title());
    }

    public function testTheBodyStartsWithTheMarkerLine(): void
    {
        self::assertStringStartsWith(JourneyAlarmTask::MARKER."\n", $this->oneBroken()->body());
    }

    public function testTheBodyNamesTheJourneyItsStepAndTheReason(): void
    {
        self::assertStringContainsString(
            '- `BrokenJourneyTest.php`: journey "broken" failed at step "read the second page": Failed asserting that two strings are identical.',
            $this->oneBroken()->body(),
        );
    }

    public function testTheBodyHoldsTheOutputTailInACodeBlock(): void
    {
        $output = (string) file_get_contents(JourneyRunTest::ONE_BROKEN);

        self::assertStringEndsWith("```\n".rtrim($output, "\n")."\n```\n", $this->oneBroken()->body());
    }

    public function testTheBodyLinksTheRunWhenOneIsGiven(): void
    {
        $task = new JourneyAlarmTask(JourneyRun::fromOutput((string) file_get_contents(JourneyRunTest::ONE_BROKEN)), Uri::parse(self::LINK));

        self::assertStringContainsString("\nRun: ".self::LINK."\n", $task->body());
    }

    public function testTheBodyHasNoRunLineWithoutALink(): void
    {
        self::assertStringNotContainsString('Run:', $this->oneBroken()->body());
    }

    public function testTheTailHoldsOnlyTheLastFortyLines(): void
    {
        $body = $this->taskAfter($this->numberedLines(60), 'A.php: journey "a" failed at step "one": x')->body();

        self::assertStringNotContainsString('line 22', $body);
        self::assertStringContainsString("```\nline 23\n", $body);
    }

    public function testAHugeTailIsCutFromItsStartSoTheBodyStaysUnderGitHubsLimit(): void
    {
        $line = str_repeat('é', 5000);
        $body = $this->taskAfter(array_fill(0, 39, $line), 'A.php: journey "a" failed at step "one": x')->body();

        self::assertSame(JourneyAlarmTask::BODY_LIMIT - 1, mb_strlen($body));
        self::assertStringEndsWith('{"result":"fail","failed":["journeys"]}'."\n```\n", $body);
    }

    public function testAFindingListOverTheLimitIsCutToTheLimit(): void
    {
        $findings = array_map(static fn (int $number): string => 'A.php: journey "a'.$number.'" failed at step "one": '.str_repeat('x', 280), range(1, 300));

        self::assertSame(JourneyAlarmTask::BODY_LIMIT - 1, mb_strlen($this->taskFor(...$findings)->body()));
    }

    public function testBackticksInTheTailGetALongerFence(): void
    {
        $body = $this->taskFor('A.php: journey "a" failed at step "one": ````')->body();

        self::assertStringContainsString("\n`````\n", $body);
    }

    private function oneBroken(): JourneyAlarmTask
    {
        return new JourneyAlarmTask(JourneyRun::fromOutput((string) file_get_contents(JourneyRunTest::ONE_BROKEN)), null);
    }

    private function taskFor(string ...$findings): JourneyAlarmTask
    {
        return $this->taskAfter([], ...$findings);
    }

    /**
     * @return list<string>
     */
    private function numberedLines(int $count): array
    {
        return array_map(static fn (int $line): string => 'line '.$line, range(1, $count));
    }

    /**
     * @param list<string> $lines
     */
    private function taskAfter(array $lines, string ...$findings): JourneyAlarmTask
    {
        $gate = json_encode(['gate' => 'journeys', 'ok' => false, 'findings' => $findings], JSON_THROW_ON_ERROR);

        return new JourneyAlarmTask(JourneyRun::fromOutput(implode("\n", [...$lines, $gate, '{"result":"fail","failed":["journeys"]}'])."\n"), null);
    }
}
