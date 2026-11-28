<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildLimitsRefused;
use Intakt\Core\Build\Infrastructure\CheckBuildLimitsPass;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(CheckBuildLimitsPass::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuildLimitsRefused::class)]
final class CheckBuildLimitsPassTest extends TestCase
{
    public function testWholeNumberLimitsPassTheContainerBuild(): void
    {
        $container = $this->containerWith([]);

        new CheckBuildLimitsPass()->process($container);

        self::assertSame(1_000, $container->getParameter(BuildServices::ALLOWANCE_TOKENS));
    }

    public function testLimitsRightAtTheirBoundaryPassTheContainerBuild(): void
    {
        $container = $this->containerWith([
            BuildServices::ALLOWANCE_TOKENS => 1,
            BuildServices::IDLE_SECONDS => 1,
            BuildServices::CEILING_TOKENS => 1,
            BuildServices::TASK_TOTAL_TOKENS => 1,
        ]);

        new CheckBuildLimitsPass()->process($container);

        self::assertSame(1, $container->getParameter(BuildServices::CEILING_TOKENS));
    }

    public function testAPhpConstantLimitIsAWholeNumberOnceTheConfigurationIsLoadedAndIsChecked(): void
    {
        $container = $this->containerWith([BuildServices::IDLE_SECONDS => constant('PHP_INT_MAX')]);

        new CheckBuildLimitsPass()->process($container);

        self::assertSame(PHP_INT_MAX, $container->getParameter(BuildServices::IDLE_SECONDS));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function limits(): iterable
    {
        foreach (CheckBuildLimitsPass::PARAMETERS as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('limits')]
    public function testALimitGivenAsAnEnvIntPlaceholderIsRefusedAtContainerBuild(string $name): void
    {
        $container = $this->containerWith([$name => '%env(int:INTAKT_BUILD_LIMIT)%']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(sprintf('The build limit %s must be a literal whole number under parameters:, not string.', $name));

        new CheckBuildLimitsPass()->process($container);
    }

    /**
     * @return iterable<string, array{bool|float|string|null, string}>
     */
    public static function notWholeNumbers(): iterable
    {
        yield 'a boolean' => [true, 'bool'];
        yield 'a numeric string' => ['600', 'string'];
        yield 'a float' => [600.0, 'float'];
        yield 'null' => [null, 'null'];
    }

    #[DataProvider('notWholeNumbers')]
    public function testALimitThatIsNotAWholeNumberIsRefusedAtContainerBuild(bool|float|string|null $value, string $type): void
    {
        $container = $this->containerWith([BuildServices::IDLE_SECONDS => $value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(sprintf('The build limit %s must be a literal whole number under parameters:, not %s.', BuildServices::IDLE_SECONDS, $type));

        new CheckBuildLimitsPass()->process($container);
    }

    public function testALimitBelowOneIsRefusedAtContainerBuild(): void
    {
        $container = $this->containerWith([BuildServices::TASK_TOTAL_TOKENS => 0]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The build limits under parameters: are refused: Every build limit must be at least 1, not allowance 1000, idle 600 s, ceiling 4000 and per-task total 0.');

        new CheckBuildLimitsPass()->process($container);
    }

    public function testARunawayCeilingBelowTheAllowanceIsRefusedAtContainerBuild(): void
    {
        $container = $this->containerWith([BuildServices::CEILING_TOKENS => 999]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The build limits under parameters: are refused: The runaway ceiling of 999 tokens is below the allowance of 1000 tokens.');

        new CheckBuildLimitsPass()->process($container);
    }

    public function testTheRefusalOfTheBuildLimitsIsKeptAsTheCauseOfTheContainerBuildFailure(): void
    {
        $container = $this->containerWith([BuildServices::CEILING_TOKENS => 999]);

        try {
            new CheckBuildLimitsPass()->process($container);
            self::fail('The container build accepted a runaway ceiling below the allowance.');
        } catch (InvalidArgumentException $refused) {
            self::assertInstanceOf(BuildLimitsRefused::class, $refused->getPrevious());
        }
    }

    /**
     * @param array<string, bool|float|int|string|null> $overrides
     */
    private function containerWith(array $overrides): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $limits = [
            BuildServices::ALLOWANCE_TOKENS => 1_000,
            BuildServices::IDLE_SECONDS => 600,
            BuildServices::CEILING_TOKENS => 4_000,
            BuildServices::TASK_TOTAL_TOKENS => 10_000,
        ];

        foreach ([...$limits, ...$overrides] as $name => $value) {
            $container->setParameter($name, $value);
        }

        return $container;
    }
}
