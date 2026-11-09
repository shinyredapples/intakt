<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JourneyRun::class)]
#[UsesClass(JourneyFailure::class)]
final class JourneyRunTest extends TestCase
{
    public const string ONE_BROKEN = __DIR__.'/Fixtures/one-broken-journey.out';
    public const string PASS = "{\"gate\":\"journeys\",\"ok\":true,\"findings\":[],\"omitted\":0}\n{\"result\":\"pass\"}\n";

    public function testTheGateOutputOfTheBrokenJourneyFixtureNamesItsFileJourneyStepAndReason(): void
    {
        $run = JourneyRun::fromOutput((string) file_get_contents(self::ONE_BROKEN));

        self::assertEquals(
            [new JourneyFailure('BrokenJourneyTest.php', 'broken', 'read the second page', 'Failed asserting that two strings are identical.')],
            $run->failures,
        );
    }

    public function testAPassingRunIsNotRed(): void
    {
        self::assertFalse(JourneyRun::fromOutput(self::PASS)->isRed());
    }

    public function testEveryFailingJourneyOfTheGateIsRead(): void
    {
        $run = JourneyRun::fromOutput($this->failingWith('A.php: journey "a" failed at step "one": x', 'B.php: journey "b" failed at step "two": y'));

        self::assertSame(['a', 'b'], array_map(static fn (JourneyFailure $failure): string => $failure->journey, $run->failures));
    }

    public function testAJourneyNameWithQuotesIsReadFromTheJsonEscapedLine(): void
    {
        $run = JourneyRun::fromOutput($this->failingWith('A.php: journey "the "odd" one" failed at step "open": gone'));

        self::assertSame(['the "odd" one', 'open'], [$run->failures[0]->journey, $run->failures[0]->step]);
    }

    public function testOutputWithNeitherAFailingJourneyNorAPassIsRefused(): void
    {
        $this->expectException(UnreadableJourneyRun::class);

        JourneyRun::fromOutput("PHP Fatal error: out of memory\n");
    }

    public function testAFailedGateWhoseFindingIsNoJourneyLineIsRefused(): void
    {
        $this->expectException(UnreadableJourneyRun::class);

        JourneyRun::fromOutput($this->failingWith('tests/Journeys: no journey found'));
    }

    public function testAFailedGateWithoutAJourneyFindingIsRefusedEvenBesideAPass(): void
    {
        $this->expectException(UnreadableJourneyRun::class);

        JourneyRun::fromOutput('{"gate":"journeys","ok":false,"findings":["X: crashed"]}'."\n".self::PASS);
    }

    public function testAFailedGateWithAJourneyFindingBesideAPassIsRed(): void
    {
        $run = JourneyRun::fromOutput($this->failingWith('A.php: journey "a" failed at step "one": x').self::PASS);

        self::assertTrue($run->isRed());
    }

    public function testEmptyOutputIsRefused(): void
    {
        $this->expectException(UnreadableJourneyRun::class);

        JourneyRun::fromOutput('');
    }

    public function testAFindingOfAnotherGateIsNoFailingJourney(): void
    {
        $this->expectException(UnreadableJourneyRun::class);

        JourneyRun::fromOutput('{"gate":"tests","ok":false,"findings":["A.php: journey \"a\" failed at step \"one\": x"]}'."\n");
    }

    public function testTheTailIsTheLastLinesOfTheWholeOutputIncludingLinesThatAreNoJson(): void
    {
        $run = JourneyRun::fromOutput(implode("\n", array_map(static fn (int $line): string => 'line '.$line, range(1, 50)))."\n".self::PASS);

        self::assertSame("line 50\n".trim(self::PASS), $run->tail(3));
    }

    private function failingWith(string ...$findings): string
    {
        return json_encode(['gate' => 'journeys', 'ok' => false, 'findings' => $findings, 'omitted' => 0], JSON_THROW_ON_ERROR)."\n".'{"result":"fail","failed":["journeys"]}'."\n";
    }
}
