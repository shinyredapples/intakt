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
use Intakt\Core\Review\Contract\PathGlob;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
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
#[UsesClass(ConfiguredBuildRouting::class)]
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
        self::assertStringContainsString('tier strong, reason setback, provider anthropic, model strong-model', $tester->getDisplay());
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
        self::assertSame(0, $ledger->count());
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

    /**
     * @param array<string, mixed> $input
     */
    private function execute(InMemoryLedger $ledger, array $input): CommandTester
    {
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => [], 'escalate_on' => ['stall']]], new ModelRouting());
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel(Provider::Anthropic, $tier->value.'-model'));
        $application = new Application();
        $application->addCommand(new ChooseBuildModelCommand(new ChooseBuildModel($routing, $tiers, $ledger)));
        $tester = new CommandTester($application->find('intakt:build:model'));
        $tester->execute($input);

        return $tester;
    }
}
