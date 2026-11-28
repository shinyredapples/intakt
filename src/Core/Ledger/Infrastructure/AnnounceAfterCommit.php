<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Override;
use SensitiveParameter;

// At the driver, because DBAL calls it only for the outermost transaction (a nested one is a savepoint), which is the commit that stores the ledger's rows.
#[AsMiddleware(connections: ['default'])]
final readonly class AnnounceAfterCommit implements Middleware
{
    public function __construct(private LedgerAnnouncements $announcements)
    {
    }

    #[Override]
    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->announcements) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly LedgerAnnouncements $announcements)
            {
                parent::__construct($driver);
            }

            #[Override]
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new AnnouncingConnection(parent::connect($params), $this->announcements);
            }
        };
    }
}
