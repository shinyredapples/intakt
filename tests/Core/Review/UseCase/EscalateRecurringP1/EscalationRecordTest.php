<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Build\UseCase\ShowBuildTier\ShowBuildTierCommand;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1Handler;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationsOfChange;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\HolderReReviews;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

// The issue's evidence record: a scripted three-round task over three heads, so the ledger shows a confirmed and a rejected escalation and their reuse.
#[Group('persistence')]
#[CoversClass(EscalateRecurringP1Handler::class)]
#[CoversClass(EscalationsOfChange::class)]
#[CoversClass(HolderReReviews::class)]
final class EscalationRecordTest extends KernelTestCase
{
    private const string CHANGE = 'escalated-change';

    private RoundFixture $fixture;

    public function testThreeRoundsOnThreeHeadsRecordAConfirmedAndARejectedEscalationThatTheThirdRoundReuses(): void
    {
        $second = $this->escalatedOnTheSecondHead();
        $third = $this->roundAt(str_repeat('c', 40), 'F1', 'F2');

        $printed = $this->ledgerEvents();

        self::assertMatchesRegularExpression('/ escalation_reviewed \{"round":"'.$second.'","finding":"F1","key":"[0-9a-f]{64}","outcome":"confirmed","reasons":"The bus drops the message on a retry\."\}/', $printed);
        self::assertMatchesRegularExpression('/ escalation_reviewed \{"round":"'.$second.'","finding":"F2","key":"[0-9a-f]{64}","outcome":"rejected","reasons":"Order is not required by the task\."\}/', $printed);
        self::assertSame(2, substr_count($printed, ' escalation_verdict_reused {"round":"'.$third.'"'));
    }

    public function testAfterTheRecordTheNextBuildOfTheChangeRunsOnTheStrongTierForTheConfirmedEscalation(): void
    {
        $this->escalatedOnTheSecondHead();

        self::assertStringContainsString('tier strong, reason confirmed-escalation', $this->buildTierOfTheChange());
    }

    public function testAfterTheRecordTheThirdRoundsReviewersSeeBothVerdictsOfTheFindingsTheyStillHold(): void
    {
        $this->escalatedOnTheSecondHead();

        $third = $this->fixture->round($this->fixture->startAtHead(self::CHANGE, str_repeat('c', 40)));

        self::assertEquals([
            'F1' => new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops the message on a retry.'),
            'F2' => new EscalationVerdict(EscalationOutcome::Rejected, 'Order is not required by the task.'),
        ], $this->fixture->escalationsOfChange()->ofOpenFindings($third));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function escalatedOnTheSecondHead(): string
    {
        $this->roundAt(str_repeat('a', 40), 'A', 'B');
        $second = $this->roundAt(str_repeat('b', 40), 'F1', 'F2');
        $verdicts = [
            'F1' => new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops the message on a retry.'),
            'F2' => new EscalationVerdict(EscalationOutcome::Rejected, 'Order is not required by the task.'),
        ];

        foreach ($this->fixture->escalations->messages as $message) {
            $this->fixture->escalate($message, new ScriptedEscalations($verdicts[$message->finding]));
        }

        return $second;
    }

    private function roundAt(string $head, string $confirmedId, string $rejectedId): string
    {
        $round = $this->fixture->startWithDiffAtHead(self::CHANGE, $head, RoundFixture::DIFF_OF_RECORDING_BUS);
        $verdict = new Verdict(Decision::Changes, [$this->p1($confirmedId, 3, 'The bus loses a message.'), $this->p1($rejectedId, 5, 'The bus keeps no order.')], [], []);

        foreach ($this->fixture->bus->messages as $message) {
            if ($this->fixture->reviewer($message->reviewerId)->round()->id()->toRfc4122() === $round) {
                $this->fixture->deliver($message, new VerdictPerRole(['Look at part 1.' => $verdict]));
            }
        }

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))(new JudgeRound($round));

        return $round;
    }

    // Names a file of the fixture's checkout, so it gets a key, and is tied to a requirement, so the goalpost rule keeps it a P1 in later rounds.
    private function p1(string $id, int $line, string $text): Finding
    {
        return new Finding($id, Severity::P1, 'RecordingBus.php', $line, null, $text, FixableBy::Builder, str_starts_with($id, 'F'), 'Every message is delivered.', null);
    }

    private function ledgerEvents(): string
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('intakt:ledger:events'));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function buildTierOfTheChange(): string
    {
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => [], 'escalate_on' => []]], new ModelRouting());
        $application = new ConsoleApplication();
        $application->addCommand(new ShowBuildTierCommand($routing, $this->fixture->escalationsOfChange()));
        $tester = new CommandTester($application->find('intakt:build:tier'));
        $tester->execute(['project' => 'alpha', '--change' => self::CHANGE]);

        return $tester->getDisplay();
    }
}
