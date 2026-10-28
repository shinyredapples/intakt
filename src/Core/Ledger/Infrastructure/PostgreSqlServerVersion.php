<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use Closure;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

// Needed because DoctrineBundle picks the platform with an empty server version before it connects, and PostgreSQL refuses that: upstream/doctrine-bundle-postgresql-empty-server-version/
final readonly class PostgreSqlServerVersion implements EnvVarProcessorInterface
{
    // DBAL 4 has no PostgreSQL platform newer than the one for 12, so naming 12 picks what a detected version would.
    private const string NEWEST_PLATFORM = '12';

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.connection_factory.dsn_parser')]
        private DsnParser $dsnParser,
    ) {
    }

    public function getEnv(string $prefix, string $name, Closure $getEnv): ?string
    {
        $driver = $this->dsnParser->parse((string) $getEnv($name))['driver'] ?? null;

        return $driver === 'pdo_pgsql' ? self::NEWEST_PLATFORM : null;
    }

    public static function getProvidedTypes(): array
    {
        return ['postgresql_server_version' => 'string'];
    }
}
