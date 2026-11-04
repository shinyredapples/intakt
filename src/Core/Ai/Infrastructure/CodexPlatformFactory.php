<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Intakt\Core\Ai\Contract\Provider as IntaktProvider;
use Symfony\AI\Platform\Bridge\Codex\ModelCatalog;
use Symfony\AI\Platform\Bridge\Codex\ResultConverter;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

// The AI Bundle has no configuration for this bridge, so its platform is built here with the bundle's event dispatcher; the structured output subscribers hang on that.
final readonly class CodexPlatformFactory
{
    public function __construct(
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param array<non-empty-string, array{class: string, capabilities: list<Capability>}> $models
     */
    public function create(ModelClientInterface $cli, array $models): PlatformInterface
    {
        $provider = new Provider(
            IntaktProvider::Codex->value,
            [new ConfinedCodexClient($cli)],
            [new ResultConverter()],
            new ModelCatalog($models),
            Contract::create([new CodexMessageBagNormalizer()]),
            $this->events,
        );

        return new Platform([$provider], eventDispatcher: $this->events);
    }
}
