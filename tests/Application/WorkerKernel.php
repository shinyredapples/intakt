<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Mcp\Infrastructure\AuthorizeToolCallsPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

// Intakt's kernel is final, so a kernel whose one service is never reset has to be built beside it; it is what the "fails when a reset is removed" tests run.
final class WorkerKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug, private readonly string $unresetService)
    {
        parent::__construct($environment, $debug);
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/worker-'.md5($this->unresetService);
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AuthorizeToolCallsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
        // Priority 0, because the framework's pass that collects the kernel.reset tags runs at -32 and must find the tag already gone.
        $container->addCompilerPass(new KeepStateOf($this->unresetService), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
    }
}
