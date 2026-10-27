<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the append-only ledger_event table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('ledger_event');
        $table->addColumn('sequence', Types::INTEGER, ['autoincrement' => true]);
        $table->addColumn('kind', Types::STRING, ['length' => 32]);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('recorded_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['sequence']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('ledger_event');
    }
}
