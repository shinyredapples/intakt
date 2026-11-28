<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Infrastructure;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class AnnouncingConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly LedgerAnnouncements $announcements)
    {
        parent::__construct($connection);
    }

    #[Override]
    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->announcements->transactionBegan();
    }

    #[Override]
    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Exception $failure) {
            // A commit that failed stored none of the held events.
            $this->announcements->rolledBack();

            throw $failure;
        }

        $this->announcements->committed();
    }

    #[Override]
    public function rollBack(): void
    {
        $this->announcements->rolledBack();
        parent::rollBack();
    }
}
