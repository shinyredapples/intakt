<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Closure;
use Doctrine\Common\EventManagerInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Tools\SchemaTool;

// Two connections to one SQLite file in WAL mode, because a write is refused for another writer's lock or commit only between connections, never on a shared one.
final readonly class TwoConnections
{
    private function __construct(
        public EntityManager $left,
        public EntityManager $right,
        private string $path,
    ) {
    }

    public static function mappedBy(Configuration $mapping): self
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'intake-');
        $left = self::connected($path, $mapping);
        $left->getConnection()->executeQuery('PRAGMA journal_mode = WAL')->free();
        new SchemaTool($left)->createSchema($left->getMetadataFactory()->getAllMetadata());

        return new self($left, self::connected($path, $mapping), $path);
    }

    // Before the left connection's next flush only, so the test forces what the other worker does between this one's read and its write.
    public function beforeTheNextLeftFlush(callable $competing): void
    {
        $events = $this->left->getEventManager();
        $events->addEventListener([Events::preFlush], new readonly class($competing(...), $events) {
            public function __construct(
                private Closure $competing,
                private EventManagerInterface $events,
            ) {
            }

            public function preFlush(): void
            {
                $this->events->removeEventListener([Events::preFlush], $this);
                ($this->competing)();
            }
        });
    }

    public function holdTheWriteLockOnTheRight(): void
    {
        $this->right->getConnection()->executeStatement('BEGIN IMMEDIATE');
    }

    public function releaseTheWriteLockOnTheRight(): void
    {
        $this->right->getConnection()->executeStatement('ROLLBACK');
    }

    public function close(): void
    {
        $this->right->getConnection()->close();
        $this->left->getConnection()->close();
        array_map(static fn (string $file): bool => !is_file($file) || unlink($file), [$this->path, $this->path.'-wal', $this->path.'-shm']);
    }

    private static function connected(string $path, Configuration $mapping): EntityManager
    {
        $entities = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]), $mapping);
        // No waiting on a lock, so a test with a held lock fails at once instead of after the driver's default wait.
        $entities->getConnection()->executeStatement('PRAGMA busy_timeout = 0');

        return $entities;
    }
}
