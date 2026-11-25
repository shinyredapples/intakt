<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Infrastructure;

use Intakt\Core\Build\Contract\BuildServices;
use Intakt\Core\Build\Domain\BuildLimits;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

// A pass, so a wrong limit stops the container from compiling instead of passing boot and failing the first build that asks for the job.
final class BuildLimitsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $limits = [
            $this->wholeNumber($container, 'allowance', BuildServices::ALLOWANCE_TOKENS),
            $this->wholeNumber($container, 'idle time', BuildServices::IDLE_SECONDS),
            $this->wholeNumber($container, 'runaway ceiling', BuildServices::CEILING_TOKENS),
            $this->wholeNumber($container, 'per-task total', BuildServices::TASK_TOTAL_TOKENS),
        ];

        // Built once here, so the ranges BuildLimits refuses are refused at compilation too.
        new BuildLimits(...$limits);

        $container->register(BuildLimits::class, BuildLimits::class)->setArguments($limits);
    }

    // Checked before any argument is typed, because the compiled container would read a YAML true as a limit of 1.
    private function wholeNumber(ContainerBuilder $container, string $limit, string $parameter): int
    {
        $given = $container->getParameterBag()->resolveValue($container->getParameter($parameter));

        if (!is_int($given)) {
            throw new InvalidArgumentException(sprintf('The build\'s %s must be a whole number, not %s.', $limit, get_debug_type($given)));
        }

        return $given;
    }
}
