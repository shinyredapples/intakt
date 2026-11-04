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

    private readonly ProcessSettings $processAtStart;

    // Taken here, once per worker before its first request, because a kernel cloned per request (FRANKENPHP_RESET_KERNEL) carries it along, where a service would be rebuilt after the leak.
    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
        $this->processAtStart = ProcessSettings::now();
    }

    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        if (self::MAIN_REQUEST === $type) {
            $this->processAtStart->restore();
        }

        return parent::handle($request, $type, $catch);
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AuthorizeToolCallsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }
}
