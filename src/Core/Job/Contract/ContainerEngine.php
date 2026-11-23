<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Set by the owner, never guessed from a socket on the host, because an install without an engine (the docker compose demo) keeps working in the app's own process.
final readonly class ContainerEngine
{
    public const string VARIABLE = 'INTAKT_CONTAINER_ENGINE';

    private const string DOCKER = 'docker';

    public function __construct(
        #[Autowire(env: 'default::'.self::VARIABLE)]
        private ?string $configured,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function isConfigured(): bool
    {
        return match ($this->configured) {
            null, '' => false,
            self::DOCKER => true,
            default => throw new InvalidArgumentException(sprintf('%s is "%s"; leave it empty for an install without a container engine, or set it to %s.', self::VARIABLE, $this->configured, self::DOCKER)),
        };
    }
}
