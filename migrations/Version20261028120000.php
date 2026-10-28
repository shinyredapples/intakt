<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refuse updates and deletes of ledger_event rows in the database';
    }

    // Only SQLite gets triggers so far; other engines rely on the refusal in Doctrine until a second engine is supported.
    public function up(Schema $schema): void
    {
        if (!$this->platform instanceof SQLitePlatform) {
            return;
        }

        foreach (['UPDATE' => 'updated', 'DELETE' => 'deleted'] as $operation => $past) {
            $this->addSql(sprintf(
                "CREATE TRIGGER ledger_event_no_%s BEFORE %s ON ledger_event BEGIN SELECT RAISE(ABORT, 'ledger_event is append-only: a stored event cannot be %s'); END",
                strtolower($operation),
                $operation,
                $past,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        if (!$this->platform instanceof SQLitePlatform) {
            return;
        }

        $this->addSql('DROP TRIGGER ledger_event_no_update');
        $this->addSql('DROP TRIGGER ledger_event_no_delete');
    }
}
