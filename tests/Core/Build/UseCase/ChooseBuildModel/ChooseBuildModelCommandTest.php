<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\ChooseBuildModel;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Build\UseCase\ChooseBuildModel\BuildAttempt;
use Intakt\Core\Build\UseCase\ChooseBuildModel\ChooseBuildModel;
use Intakt\Core\Build\UseCase\ChooseBuildModel\ChooseBuildModelCommand;
use Intakt\Core\Build\UseCase\ChooseBuildModel\ChooseBuildModelInput;
use Intakt\Core\Build\UseCase\ChooseBuildModel\ChosenModel;
use Intakt\Core\Build\UseCase\ChooseBuildModel\ModelChoiceNotRecorded;
use Intakt\Core\Build\UseCase\RunBuildJob\BuilderNotOnClaudeCode;
use Intakt\Core\Review\Contract\ChangeEscalations;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationStillPending;
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ChooseBuildModelCommand::class)]
#[UsesClass(ChooseBuildModelInput::class)]
#[UsesClass(ChooseBuildModel::class)]
#[UsesClass(BuildAttempt::class)]
#[UsesClass(ChosenModel::class)]
#[UsesClass(ModelChoiceNotRecorded::class)]
#[UsesClass(BuilderNotOnClaudeCode::class)]
#[UsesClass(ConfiguredBuildRouting::class)]
#[UsesClass(EscalationStillPending::class)]
#[UsesClass(BuildRoutingNotConfigured::class)]
#[UsesClass(ModelRouting::class)]
#[UsesClass(RoutingRules::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(PathGlob::class)]
final class ChooseBuildModelCommandTest extends TestCase
{
    public function testItPrintsTheChosenTierReasonAndModelAndRecordsOneEvent(): void
    {
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, ['project' => 'alpha', 'task' => 'T60', 'attempt' => '2', '--setback' => 'stall']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('tier strong, reason setback, provider claude_code, model strong-model', $tester->getDisplay());
        self::assertSame(1, $ledger->count());
    }

    public function testItRefusesAnUnknownSetbackWithoutRecording(): void
    {
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, ['project' => 'alpha', 'task' => 'T60', 'attempt' => '1', '--setback' => 'oops']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $ledger->count());
    }

    public function testItRefusesAnAttemptBelowOneWithoutRecording(): void
    {
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, ['project' => 'alpha', 'task' => 'T60', 'attempt' => '0']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('An attempt number starts at 1.', $tester->getDisplay());
        self::assertSame(0, $ledger->count());
    }

    #[DataProvider('refusedAttempts')]
    public function testItRefusesAnEmptyOrNonUtf8ProjectOrTaskWithAMessageWithoutRecording(string $project, string $task, string $message): void
    {
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, ['project' => $project, 'task' => $task, 'attempt' => '1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertSame(0, $ledger->count());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedAttempts(): iterable
    {
        yield 'an empty project' => ['', 'T60', 'A project must not be empty.'];
        yield 'an empty task' => ['alpha', '', 'A task must not be empty.'];
        yield 'a project that is not UTF-8' => ["alpha\xFF", 'T60', 'A project must be valid UTF-8.'];
        yield 'a task that is not UTF-8' => ['alpha', "T\xC360", 'A task must be valid UTF-8.'];
    }

    public function testItFailsForAProjectThatIsNotConfigured(): void
    {
        $tester = $this->execute(new InMemoryLedger(), ['project' => 'gamma', 'task' => 'T60', 'attempt' => '1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Project "gamma" is not configured.', $tester->getDisplay());
    }

    public function testItReportsAChoiceTheLedgerDidNotRecord(): void
    {
        $tester = $this->execute(new InMemoryLedger(0), ['project' => 'alpha', 'task' => 'T60', 'attempt' => '1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('did not record the model choice', $tester->getDisplay());
    }

    public function testTheNextBuildOfAChangeWhoseLatestRoundConfirmedAnEscalationRunsOnTheStrongTierWithThatReason(): void
    {
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, ['project' => 'alpha', 'task' => 'T61', 'attempt' => '3', '--change' => 'feat/confirmed']);

        self::assertStringContainsString('tier strong, reason confirmed-escalation, provider claude_code, model strong-model', $tester->getDisplay());
        self::assertSame('confirmed-escalation', $ledger->payloads()[0]['reason'] ?? null);
    }

    public function testItChoosesAndRecordsNoModelForAChangeWhoseEscalationReviewHasNotAnsweredYet(): void
    {
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, ['project' => 'alpha', 'task' => 'T61', 'attempt' => '3', '--change' => 'feat/pending']);

        self::assertSame([Command::FAILURE, 0], [$tester->getStatusCode(), $ledger->count()]);
        self::assertStringContainsString('is still pending', $tester->getDisplay());
    }

    public function testItReportsATierOnAProviderABuildCannotRunOnWithoutRecording(): void
    {
        $ledger = new InMemoryLedger();

        $tester = $this->execute($ledger, ['project' => 'alpha', 'task' => 'T60', 'attempt' => '1'], Provider::Anthropic);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('needs provider "claude_code"', $tester->getDisplay());
        self::assertSame(0, $ledger->count());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(InMemoryLedger $ledger, array $input, Provider $provider = Provider::ClaudeCode): CommandTester
    {
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => [], 'escalate_on' => ['stall']]], new ModelRouting());
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel($provider, $tier->value.'-model'));
        $application = new Application();
        $escalations = self::createStub(ChangeEscalations::class);
        $escalations->method('outcomeAtLatestHead')->willReturnCallback(static fn (string $project, string $change): ?EscalationOutcome => 'feat/pending' === $change ? throw EscalationStillPending::of($change) : EscalationOutcome::tryFrom(substr($change, strlen('feat/'))));
        $application->addCommand(new ChooseBuildModelCommand(new ChooseBuildModel($routing, $tiers, $ledger), $escalations));
        $tester = new CommandTester($application->find('intakt:build:model'));
        $tester->execute($input);

        return $tester;
    }
}
