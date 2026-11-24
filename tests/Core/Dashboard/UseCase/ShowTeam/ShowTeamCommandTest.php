<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\AccountCooldowns;
use Intakt\Core\Ai\Contract\EnabledAccounts;
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
final class ShowTeamCommandTest extends TestCase
{
    public function testItPrintsEachAccountOnceWithItsStateAndTheRolesItServes(): void
    {
        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString("account anthropic.default: ready, serves developer\naccount openai.default: cooling-down, serves developer\naccount codex.default: ready, serves no role\n", $tester->getDisplay());
    }

    public function testItPrintsTheProvidersTheDeveloperRoleCanBuildOn(): void
    {
        self::assertStringContainsString("role developer: openai, anthropic\n", $this->execute()->getDisplay());
    }

    private function execute(): CommandTester
    {
        $enabled = self::createStub(EnabledAccounts::class);
        $enabled->method('of')->willReturnCallback(static fn (Provider $provider): array => in_array($provider, [Provider::Anthropic, Provider::OpenAi, Provider::Codex], true) ? [new Account($provider, Account::DEFAULT)] : []);
        $cooldowns = self::createStub(AccountCooldowns::class);
        $cooldowns->method('isCoolingDown')->willReturnCallback(static fn (Account $account): bool => Provider::OpenAi === $account->provider);
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(new ProjectNames('alpha'));
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => match ($tier) {
            Tier::Strong => new ResolvedModel(Provider::Anthropic, 'model-l'),
            Tier::Standard => new ResolvedModel(Provider::OpenAi, 'model-m'),
            Tier::Light => throw new TierNotMapped($project, $tier),
        });
        $routing = new ConfiguredBuildRouting(['alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => ['src/Security/**'], 'escalate_on' => []]], new ModelRouting());
        $application = new Application();
        $application->addCommand(new ShowTeamCommand(new TeamRoster($enabled, $cooldowns, $tiers, $routing)));
        $tester = new CommandTester($application->find('intakt:team'));
        $tester->execute([]);

        return $tester;
    }
}
