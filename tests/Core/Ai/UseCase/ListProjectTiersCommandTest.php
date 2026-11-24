<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ai\UseCase;

use Intakt\Core\Ai\Contract\ProjectNames;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\ResolvedModel;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ai\Contract\TierNotMapped;
use Intakt\Core\Ai\Contract\TierResolver;
use Intakt\Core\Ai\UseCase\ListProjectTiers\ListProjectTiersCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ListProjectTiersCommand::class)]
#[UsesClass(ResolvedModel::class)]
#[UsesClass(Provider::class)]
#[UsesClass(Tier::class)]
#[UsesClass(TierNotMapped::class)]
final class ListProjectTiersCommandTest extends TestCase
{
    public function testEveryTierOfEveryProjectIsListedWithItsModelOrItsProblem(): void
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(new ProjectNames('alpha'));
        $resolver->method('resolve')->willReturnCallback(static fn (string $project, Tier $tier): ResolvedModel => match ($tier) {
            Tier::Strong => new ResolvedModel(Provider::Anthropic, 'big'),
            default => throw new TierNotMapped($project, $tier),
        });

        $tester = $this->execute($resolver);

        $output = $tester->getDisplay();
        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('/alpha\s+strong\s+anthropic\s+big/', $output);
        self::assertStringContainsString('Project "alpha" maps no model to the "light" tier.', $output);
    }

    public function testWithoutProjectsItSaysSo(): void
    {
        $resolver = self::createStub(TierResolver::class);
        $resolver->method('projects')->willReturn(new ProjectNames());

        self::assertStringContainsString('No projects are configured.', $this->execute($resolver)->getDisplay());
    }

    private function execute(TierResolver $resolver): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ListProjectTiersCommand($resolver));
        $tester = new CommandTester($application->find('intakt:ai:tiers'));
        $tester->execute([]);

        return $tester;
    }
}
