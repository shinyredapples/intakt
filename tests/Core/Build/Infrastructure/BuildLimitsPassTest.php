<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Infrastructure\BuildLimitsPass;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(BuildLimitsPass::class)]
#[UsesClass(BuildLimits::class)]
final class BuildLimitsPassTest extends TestCase
{
    public function testWholeNumberLimitsBecomeTheBuildLimitsService(): void
    {
        $container = $this->containerWith([]);

        new BuildLimitsPass()->process($container);

        self::assertSame([2_000_000, 900, 6_000_000, 12_000_000], $container->getDefinition(BuildLimits::class)->getArguments());
    }

    public function testALimitGivenAsAParameterReferenceIsReadAsItsValue(): void
    {
        $container = $this->containerWith([BuildServices::IDLE_SECONDS => '%other%']);
        $container->setParameter('other', 60);

        new BuildLimitsPass()->process($container);

        self::assertSame(60, $container->getDefinition(BuildLimits::class)->getArgument(1));
    }

    #[TestWith([BuildServices::ALLOWANCE_TOKENS, 'allowance', true, 'bool'])]
    #[TestWith([BuildServices::IDLE_SECONDS, 'idle time', '900', 'string'])]
    #[TestWith([BuildServices::CEILING_TOKENS, 'runaway ceiling', 6_000_000.0, 'float'])]
    #[TestWith([BuildServices::TASK_TOTAL_TOKENS, 'per-task total', null, 'null'])]
    public function testALimitThatIsNotAWholeNumberIsRefused(string $parameter, string $limit, mixed $given, string $type): void
    {
        $container = $this->containerWith([$parameter => $given]);

        $this->expectExceptionObject(new InvalidArgumentException(sprintf('The build\'s %s must be a whole number, not %s.', $limit, $type)));

        new BuildLimitsPass()->process($container);
    }

    public function testALimitOfZeroIsRefused(): void
    {
        $container = $this->containerWith([BuildServices::IDLE_SECONDS => 0]);

        $this->expectExceptionObject(new InvalidArgumentException('Every build limit must be at least 1, not allowance 2000000, idle 0 s, ceiling 6000000 and per-task total 12000000.'));

        new BuildLimitsPass()->process($container);
    }

    public function testARunawayCeilingBelowTheAllowanceIsRefused(): void
    {
        $container = $this->containerWith([BuildServices::CEILING_TOKENS => 1_999_999]);

        $this->expectExceptionObject(new InvalidArgumentException('The runaway ceiling of 1999999 tokens is below the allowance of 2000000 tokens.'));

        new BuildLimitsPass()->process($container);
    }

    public function testARunawayCeilingEqualToTheAllowanceIsAllowed(): void
    {
        $container = $this->containerWith([BuildServices::CEILING_TOKENS => 2_000_000]);

        new BuildLimitsPass()->process($container);

        self::assertSame(2_000_000, $container->getDefinition(BuildLimits::class)->getArgument(2));
    }

    /**
     * @param array<string, mixed> $changed
     */
    private function containerWith(array $changed): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $limits = [BuildServices::ALLOWANCE_TOKENS => 2_000_000, BuildServices::IDLE_SECONDS => 900, BuildServices::CEILING_TOKENS => 6_000_000, BuildServices::TASK_TOTAL_TOKENS => 12_000_000];

        foreach ([...$limits, ...$changed] as $parameter => $value) {
            $container->setParameter($parameter, $value);
        }

        return $container;
    }
}
