<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\EscalateRecurringP1;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Infrastructure\ConfiguredTierResolver;
use Intakt\Core\Ai\Infrastructure\TierPlatform;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Build\UseCase\ShowBuildTier\ShowBuildTierCommand;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationVerdict;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\EscalationAgent;
use Intakt\Core\Review\Infrastructure\EscalationPrompt;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalateRecurringP1Handler;
use Intakt\Core\Review\UseCase\EscalateRecurringP1\EscalationsOfChange;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiEscalationReviews;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\VendorAccountLimit;
use Intakt\Tests\Core\Ai\Infrastructure\NoCooldowns;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\UseCase\JudgeRound\ScriptedJudgement;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Semaphore\SemaphoreFactory;
use Symfony\Component\Semaphore\Store\LockStore;

// The issue's evidence record: a scripted three-round task over three heads, the strong tier answered by Symfony AI's mock platform, so the ledger shows a confirmed and a rejected escalation and their reuse.
#[Group('persistence')]
#[CoversClass(EscalateRecurringP1Handler::class)]
#[CoversClass(EscalationsOfChange::class)]
#[UsesClass(SymfonyAiEscalationReviews::class)]
#[UsesClass(EscalationAgent::class)]
#[UsesClass(EscalationPrompt::class)]
#[UsesClass(ShowBuildTierCommand::class)]
#[UsesClass(ModelRouting::class)]
final class EscalationRecordTest extends KernelTestCase
{
    private const string CHANGE = 'escalated-change';
    private const string CONFIRMED_TEXT = 'The bus loses a message.';
    private const string REJECTED_TEXT = 'The bus keeps no order.';

    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer(), diff: RoundFixture::DIFF_OF_RECORDING_BUS);
    }

    public function testThreeRoundsOnThreeHeadsRecordAConfirmedAndARejectedEscalationThatTheThirdRoundReuses(): void
    {
        $this->roundAt(str_repeat('a', 40), 'A', 'B');
        $second = $this->roundAt(str_repeat('b', 40), 'F1', 'F2');
        $this->escalateEveryQueued();
        $third = $this->roundAt(str_repeat('c', 40), 'F1', 'F2');

        $printed = $this->ledgerEvents();

        self::assertMatchesRegularExpression('/ escalation_reviewed \{"round":"'.$second.'","finding":"F1","key":"[0-9a-f]{64}","outcome":"confirmed","reasons":"The bus drops the message on a retry\."\}/', $printed);
        self::assertMatchesRegularExpression('/ escalation_reviewed \{"round":"'.$second.'","finding":"F2","key":"[0-9a-f]{64}","outcome":"rejected","reasons":"Order is not required by the task\."\}/', $printed);
        self::assertSame(2, substr_count($printed, ' escalation_verdict_reused {"round":"'.$third.'"'));
    }

    public function testAfterTheRecordTheNextBuildOfTheChangeRunsOnTheStrongTierForTheConfirmedEscalation(): void
    {
        $this->roundAt(str_repeat('a', 40), 'A', 'B');
        $this->roundAt(str_repeat('b', 40), 'F1', 'F2');
        $this->escalateEveryQueued();

        self::assertStringContainsString('tier strong, reason confirmed-escalation', $this->buildTierOf(self::CHANGE));
    }

    public function testAfterTheRecordTheThirdRoundsReviewersSeeBothVerdictsOfTheFindingsTheyStillHold(): void
    {
        $this->roundAt(str_repeat('a', 40), 'A', 'B');
        $this->roundAt(str_repeat('b', 40), 'F1', 'F2');
        $this->escalateEveryQueued();

        $third = $this->fixture->round($this->fixture->startAtHead(self::CHANGE, str_repeat('c', 40)));

        self::assertEquals([
            'F1' => new EscalationVerdict(EscalationOutcome::Confirmed, 'The bus drops the message on a retry.'),
            'F2' => new EscalationVerdict(EscalationOutcome::Rejected, 'Order is not required by the task.'),
        ], $this->fixture->escalationsOfChange()->ofOpenFindings($third));
    }

    private function roundAt(string $head, string $confirmedId, string $rejectedId): string
    {
        $round = $this->fixture->startAtHead(self::CHANGE, $head);
        $verdict = new Verdict(Decision::Changes, [$this->p1($confirmedId, 3, self::CONFIRMED_TEXT), $this->p1($rejectedId, 5, self::REJECTED_TEXT)], [], []);

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
        return new Finding($id, Severity::P1, 'RecordingBus.php', $line, null, $text, FixableBy::Builder, 'A' !== $id && 'B' !== $id, 'Every message is delivered.', null);
    }

    private function escalateEveryQueued(): void
    {
        $reviews = $this->strongTier();

        foreach ($this->fixture->escalations->messages as $message) {
            $this->fixture->escalate($message, $reviews);
        }
    }

    // Answers by the finding in the prompt, because each escalation is asked about one finding alone and the record needs one of each outcome.
    private function strongTier(): SymfonyAiEscalationReviews
    {
        $answer = static fn ($model, array|string $payload): string => str_contains(json_encode($payload, JSON_THROW_ON_ERROR), self::CONFIRMED_TEXT)
            ? '{"outcome":"confirmed","reasons":"The bus drops the message on a retry."}'
            : '{"outcome":"rejected","reasons":"Order is not required by the task."}';
        $resolver = new ConfiguredTierResolver(['alpha' => ['tiers' => [
            'strong' => ['provider' => 'anthropic', 'model' => 'big'],
            'light' => ['provider' => 'anthropic', 'model' => 'small'],
        ]]], []);
        $platform = MockPlatformFactory::createPlatform($answer, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $platforms = new ServiceLocator([Provider::Anthropic->value => static fn (): PlatformInterface => $platform]);
        $usage = new UsageLedger(new InMemoryLedger(), new MockClock('2026-11-21 12:00:00 UTC'), new NullLogger(), StoredSerializerFactory::create());
        $limit = new VendorAccountLimit(new SemaphoreFactory(new LockStore(new LockFactory(new InMemoryStore()))), 1);

        return new SymfonyAiEscalationReviews(new TierPlatform($resolver, $platforms, new NoCooldowns(), new NullLogger()), new EscalationAgent(new EscalationPrompt(new PromptCut(new Fence()))), $usage, $limit);
    }

    private function ledgerEvents(): string
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('intakt:ledger:events'));
        $tester->execute([]);

        return $tester->getDisplay();
    }

    private function buildTierOf(string $change): string
    {
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => [], 'escalate_on' => []]], new ModelRouting());
        $application = new ConsoleApplication();
        $application->addCommand(new ShowBuildTierCommand($routing, $this->fixture->escalationsOfChange()));
        $tester = new CommandTester($application->find('intakt:build:tier'));
        $tester->execute(['project' => 'alpha', '--change' => $change]);

        return $tester->getDisplay();
    }
}
