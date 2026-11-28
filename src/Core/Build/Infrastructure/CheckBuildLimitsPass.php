<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildLimitsRefused;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// At container build, because a limit refused only when the first build starts would leave a running install that cannot build.
final readonly class CheckBuildLimitsPass implements CompilerPassInterface
{
    // In the order of the BuildLimits constructor, because the values are handed to it as they come.
    public const array PARAMETERS = [
        BuildServices::ALLOWANCE_TOKENS,
        BuildServices::IDLE_SECONDS,
        BuildServices::CEILING_TOKENS,
        BuildServices::TASK_TOTAL_TOKENS,
    ];

    public function process(ContainerBuilder $container): void
    {
        $limits = array_map(fn (string $name): int => $this->literalWholeNumber($container, $name), self::PARAMETERS);

        try {
            new BuildLimits(...$limits);
        } catch (BuildLimitsRefused $refused) {
            throw new InvalidArgumentException(sprintf('The build limits under parameters: are refused: %s', $refused->getMessage()), $refused->getCode(), previous: $refused);
        }
    }

    // Read raw, because an %env(int:...)% value is still a placeholder string here and would only be known when a build starts.
    private function literalWholeNumber(ContainerBuilder $container, string $name): int
    {
        $value = $container->getParameter($name);

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('The build limit %s must be a literal whole number under parameters:, not %s.', $name, get_debug_type($value)));
        }

        return $value;
    }
}
