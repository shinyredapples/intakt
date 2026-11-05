<?php

declare(strict_types=1);

namespace Intakt;

use Intakt\Core\Mcp\Infrastructure\AuthorizeToolCallsPass;
use Intakt\Core\Server\Infrastructure\ProcessSettings;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private ?ProcessSettings $processAtStart = null;

    // Taken at the first boot, before the worker's first request, and kept by the kernel, because a kernel cloned per request (FRANKENPHP_RESET_KERNEL) carries it along, where a service would be rebuilt after the leak.
    public function boot(): void
    {
        parent::boot();
        $this->processAtStart ??= ProcessSettings::now();
    }

    // After the terminate listeners, because they run in the request too; the runner terminates before it clones, so the next request starts clean in both modes.
    public function terminate(Request $request, Response $response): void
    {
        try {
            parent::terminate($request, $response);
        } finally {
            $this->processAtStart?->restore();
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AuthorizeToolCallsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }
}
