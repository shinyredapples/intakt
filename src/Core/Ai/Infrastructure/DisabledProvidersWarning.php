<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

// Presence of a key is baked into the container, so the warning is given when it is baked (cache warm-up) and when a command starts.
final readonly class DisabledProvidersWarning implements CacheWarmerInterface
{
    /**
     * @param array<string, string> $disabledProviders provider name to message
     */
    public function __construct(
        #[Autowire('%intakt.ai.disabled_providers%')]
        private array $disabledProviders,
        private LoggerInterface $logger,
    ) {
    }

    public function isOptional(): bool
    {
        return false;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->warn();

        return [];
    }

    #[AsEventListener(event: ConsoleCommandEvent::class)]
    public function onConsoleCommand(): void
    {
        $this->warn();
    }

    private function warn(): void
    {
        foreach ($this->disabledProviders as $message) {
            $this->logger->warning($message);
        }
    }
}
