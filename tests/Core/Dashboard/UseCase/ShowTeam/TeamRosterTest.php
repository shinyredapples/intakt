<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Build\Contract\BuildFacts;
use Intakt\Core\Build\Contract\BuildRouting;
use Intakt\Core\Build\Contract\BuildRoutingNotConfigured;
use Intakt\Core\Build\Contract\TierChoice;
use Intakt\Core\Build\Contract\TierReason;
use Intakt\Core\Build\Domain\ModelRouting;
use Intakt\Core\Build\Infrastructure\ConfiguredBuildRouting;
use Intakt\Core\Dashboard\UseCase\ShowTeam\TeamRoster;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\ReviewerRoles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TeamRoster::class)]
#[UsesClass(BuildFacts::class)]
#[UsesClass(BuildRoutingNotConfigured::class)]
#[UsesClass(ConfiguredBuildRouting::class)]
#[UsesClass(ModelRouting::class)]
#[UsesClass(Paths::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(TierChoice::class)]
final class TeamRosterTest extends TestCase
{
    public function testAProjectWithoutBuildRoutingAddsNoDeveloperProvider(): void
    {
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(['alpha', 'beta']);
        $tiers->method('configured')->willReturn(new ResolvedModel(Provider::Codex, 'model-a'));
        $routing = self::createStub(BuildRouting::class);
        $routing->method('tierFor')->willReturnCallback(static fn (string $project): TierChoice => 'alpha' === $project
            ? new TierChoice(Tier::Standard, TierReason::Default)
            : throw new BuildRoutingNotConfigured($project));

        $roles = new TeamRoster(self::createStub(ReviewerRoles::class), $tiers, $routing)->providersByRole();

        self::assertSame(['Developer' => [Provider::Codex]], $roles);
    }

    public function testTheDeveloperServesTheStrongTierThatSensitivePathsRouteTo(): void
    {
        $routing = new ConfiguredBuildRouting([
            'alpha' => ['tier' => Tier::Standard->value, 'sensitive_paths' => ['config/**'], 'escalate_on' => []],
        ], new ModelRouting());
        $tiers = self::createStub(TierResolver::class);
        $tiers->method('projects')->willReturn(['alpha']);
        $tiers->method('configured')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => new ResolvedModel(
            Tier::Strong === $tier ? Provider::Codex : Provider::Anthropic,
            'model-a',
        ));

        $roles = new TeamRoster(self::createStub(ReviewerRoles::class), $tiers, $routing)->providersByRole();

        self::assertSame(['Developer' => [Provider::Anthropic, Provider::Codex]], $roles);
    }

    public function testNoProjectMeansNoDeveloperRole(): void
    {
        $reviewers = self::createStub(ReviewerRoles::class);
        $reviewers->method('servedBy')->willReturnCallback(static fn (Provider $provider): array => Provider::Anthropic === $provider ? ['correctness'] : []);

        $roles = new TeamRoster($reviewers, self::createStub(TierResolver::class), self::createStub(BuildRouting::class))->providersByRole();

        self::assertSame(['correctness' => [Provider::Anthropic]], $roles);
    }
}
