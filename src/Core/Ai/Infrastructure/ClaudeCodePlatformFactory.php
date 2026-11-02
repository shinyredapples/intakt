<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Infrastructure;

use Symfony\AI\Platform\Bridge\ClaudeCode\Contract\ClaudeCodeContract;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelCatalog;
use Symfony\AI\Platform\Bridge\ClaudeCode\ResultConverter;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

// The AI Bundle has no configuration for this bridge, so its platform is built here with the bundle's event dispatcher; the structured output subscribers hang on that.
final readonly class ClaudeCodePlatformFactory
{
    private const string NAME = 'claude_code';

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
            self::NAME,
            [new ConfinedClaudeCodeClient($cli)],
            [new UnstreamedCliConverter(new ResultConverter())],
            new ModelCatalog($models),
            ClaudeCodeContract::create(),
            $this->events,
        );

        return new Platform([$provider], eventDispatcher: $this->events);
    }
}
