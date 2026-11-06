<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Core\Mcp\Infrastructure\AuthorizeToolCallsPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;

// Intakt's configuration plus one generic platform whose provider answers from memory, because the test install has no account and so no platform of the bundle whose state a request could leave behind.
final class AiPlatformKernel extends Kernel
{
    use MicroKernelTrait {
        registerContainerConfiguration as private registerIntaktConfiguration;
    }

    public const string PLATFORM = 'ai.platform.generic.probe';

    public function getCacheDir(): string
    {
        return parent::getCacheDir().'/ai-platform';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $this->registerIntaktConfiguration($loader);
        $loader->load(static function (ContainerBuilder $container): void {
            $container->register(ProviderAnswers::class)->setPublic(true);
            // Reset as the framework resets its own http_client, so the probe does not report the mock's request count.
            $container->register('probe.http_client', MockHttpClient::class)->setArguments([new Reference(ProviderAnswers::class), 'http://localhost'])->addTag('kernel.reset', ['method' => 'reset']);
            $container->loadFromExtension('ai', ['platform' => ['generic' => ['probe' => ['base_url' => 'http://localhost', 'http_client' => 'probe.http_client']]]]);
        });
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AuthorizeToolCallsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }
}
