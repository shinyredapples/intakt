<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\ShowBuildTier;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Build\UseCase\ShowBuildTier\ShowBuildTierCommand;
use Intakt\Core\Build\UseCase\ShowBuildTier\ShowBuildTierInput;
use Intakt\Core\Review\Contract\ChangeEscalations;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\EscalationStillPending;
use Intakt\Core\Review\Contract\PathGlob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ShowBuildTierCommand::class)]
#[UsesClass(ShowBuildTierInput::class)]
#[UsesClass(ConfiguredBuildRouting::class)]
#[UsesClass(BuildRoutingNotConfigured::class)]
#[UsesClass(ModelRouting::class)]
#[UsesClass(RoutingRules::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(PathGlob::class)]
#[UsesClass(EscalationStillPending::class)]
final class ShowBuildTierCommandTest extends TestCase
{
    public function testItPrintsTheDefaultTierForAnOrdinaryBuild(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--path' => ['README.md']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('tier standard, reason default', $tester->getDisplay());
    }

    public function testItPrintsTheStrongTierForAModelLabel(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--label' => ['model:strong'], '--path' => ['README.md']]);

        self::assertStringContainsString('tier strong, reason label', $tester->getDisplay());
    }

    public function testItPrintsTheStrongTierForASensitivePath(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--path' => ['src/Security/Login.php']]);

        self::assertStringContainsString('tier strong, reason sensitive-path', $tester->getDisplay());
    }

    public function testItPrintsTheStrongTierAndSetbackReasonForABlockingFinding(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--setback' => 'blocking-finding']);

        self::assertStringContainsString('tier strong, reason setback', $tester->getDisplay());
    }

    public function testItKeepsTheDefaultTierForASetbackTheProjectDoesNotEscalateOn(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--setback' => 'failed-attempt']);

        self::assertStringContainsString('tier standard, reason default', $tester->getDisplay());
    }

    public function testItPrintsTheStrongTierAndConfirmedEscalationReasonForAChangeWhoseLatestRoundConfirmedAnEscalation(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--change' => 'feat/confirmed']);

        self::assertStringContainsString('tier strong, reason confirmed-escalation', $tester->getDisplay());
    }

    public function testItKeepsTheDefaultTierForAChangeWhoseLatestRoundRejectedTheEscalation(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--change' => 'feat/rejected']);

        self::assertStringContainsString('tier standard, reason default', $tester->getDisplay());
    }

    public function testItChoosesNoTierForAChangeWhoseEscalationReviewHasNotAnsweredYet(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--change' => 'feat/pending']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('is still pending', $tester->getDisplay());
        self::assertStringNotContainsString('tier standard', $tester->getDisplay());
    }

    public function testItRefusesAnUnknownSetback(): void
    {
        $tester = $this->execute(['project' => 'alpha', '--setback' => 'oops']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown setback "oops"', $tester->getDisplay());
    }

    public function testItFailsForAProjectThatIsNotConfigured(): void
    {
        $tester = $this->execute(['project' => 'gamma']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Project "gamma" is not configured.', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): CommandTester
    {
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => ['src/**/Security/**'], 'escalate_on' => ['blocking-finding', 'stall']]], new ModelRouting());
        $application = new Application();
        $escalations = self::createStub(ChangeEscalations::class);
        $escalations->method('outcomeAtLatestHead')->willReturnCallback(static fn (string $project, string $change): ?EscalationOutcome => 'feat/pending' === $change ? throw EscalationStillPending::of($change) : EscalationOutcome::tryFrom(substr($change, strlen('feat/'))));
        $application->addCommand(new ShowBuildTierCommand($routing, $escalations));
        $tester = new CommandTester($application->find('intakt:build:tier'));
        $tester->execute($input);

        return $tester;
    }
}
