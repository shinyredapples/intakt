<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
use Intakt\Core\Ai\Contract\ListedAccounts;
use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Domain\RoutingRules;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Dashboard\UseCase\ShowTeam\ShowTeamCommand;
use Intakt\Core\Dashboard\UseCase\ShowTeam\Team;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamAccount;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRole;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Infrastructure\ConfiguredPanels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ShowTeamCommand::class)]
#[UsesClass(TeamRoster::class)]
#[UsesClass(Team::class)]
#[UsesClass(TeamAccount::class)]
#[UsesClass(TeamRole::class)]
#[UsesClass(ConfiguredBuildRouting::class)]
#[UsesClass(ModelRouting::class)]
#[UsesClass(RoutingRules::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(TierChoice::class)]
#[UsesClass(TierNotMapped::class)]
#[UsesClass(ConfiguredPanels::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
final class ShowTeamCommandTest extends TestCase
{
    public function testItPrintsEachAccountOnceWithItsStateAndTheRolesItServes(): void
    {
        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("account anthropic.default: ready, serves reviewer correctness\naccount anthropic.team: no-key, serves reviewer correctness\naccount openai.default: cooling-down, serves no role\naccount claude_code.default: ready, serves developer\n", $tester->getDisplay());
    }

    public function testItPrintsTheProvidersEachRoleCanRunOn(): void
    {
        self::assertStringContainsString("role developer: openai (the build runtime cannot run it), claude_code\nrole reviewer correctness: anthropic\n", $this->execute()->getDisplay());
    }

    public function testAReviewerNameThatLooksLikeConsoleMarkupIsPrintedAsConfigured(): void
    {
        $display = $this->execute('<info>security</info>')->getDisplay();

        self::assertStringContainsString("account anthropic.default: ready, serves reviewer <info>security</info>\n", $display);
        self::assertStringContainsString("role reviewer <info>security</info>: anthropic\n", $display);
    }

    /**
     * @param non-empty-string $reviewer
     */
    private function execute(string $reviewer = 'correctness'): CommandTester
    {
        $listed = self::createStub(ListedAccounts::class);
        $listed->method('of')->willReturnCallback(static fn (Provider $provider): array => match ($provider) {
            Provider::Anthropic => [new Account($provider, Account::DEFAULT), new Account($provider, 'team')],
            Provider::OpenAi, Provider::ClaudeCode => [new Account($provider, Account::DEFAULT)],
            default => [],
        });
        $enabled = self::createStub(EnabledAccounts::class);
        $enabled->method('of')->willReturnCallback(static fn (Provider $provider): array => [new Account($provider, Account::DEFAULT)]);
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('isCoolingDown')->willReturnCallback(static fn (Account $account): bool => Provider::OpenAi === $account->provider);
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(new ProjectNames('alpha'));
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => match ($tier) {
            Tier::Strong => new ResolvedModel(Provider::ClaudeCode, 'model-l'),
            Tier::Standard => new ResolvedModel(Provider::OpenAi, 'model-m'),
            Tier::Light => throw new TierNotMapped($project, $tier),
        });
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => ['src/Security/**'], 'escalate_on' => []]], new ModelRouting());
        $panels = new ConfiguredPanels(['alpha' => ['quorum' => 'all', 'reviewers' => [['role' => $reviewer, 'tier' => Tier::Strong->value, 'provider' => Provider::Anthropic->value, 'prompt' => 'Review the change.', 'budget' => 1]]]]);
        $application = new Application();
        $application->addCommand(new ShowTeamCommand(new TeamRoster($listed, $enabled, $cooldowns, $tiers, $routing, $panels)));
        $tester = new CommandTester($application->find('intakt:team'));
        $tester->execute([]);

        return $tester;
    }
}
