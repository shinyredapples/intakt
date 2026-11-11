<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class EgressRoute
{
    private const string NO_NETWORK = 'none';

    /**
     * @param list<string> $environment
     */
    private function __construct(
        public string $networkMode,
        public array $environment,
    ) {
    }

    public static function none(): self
    {
        return new self(self::NO_NETWORK, []);
    }

    // Both spellings, because curl and Composer read the lowercase names and other tools the uppercase ones.
    public static function through(string $network, string $proxy): self
    {
        return new self($network, ['HTTP_PROXY='.$proxy, 'HTTPS_PROXY='.$proxy, 'http_proxy='.$proxy, 'https_proxy='.$proxy]);
    }

    public function isNone(): bool
    {
        return self::NO_NETWORK === $this->networkMode;
    }
}
