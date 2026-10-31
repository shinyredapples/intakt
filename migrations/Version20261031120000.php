<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the start of a reviewer hold to round_reviewer and create the messenger_messages queue table';
    }

    public function up(Schema $schema): void
    {
        $startedAt = Column::editor()->setUnquotedName('started_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('round_reviewer'), addedColumns: [$startedAt])) as $sql) {
            $this->addSql($sql);
        }

        foreach ($this->platform->getCreateTableSQL($this->messengerMessages()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $startedAt = $schema->getTable('round_reviewer')->getColumn('started_at');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('round_reviewer'), droppedColumns: [$startedAt])) as $sql) {
            $this->addSql($sql);
        }

        $this->addSql($this->platform->getDropTableSQL('messenger_messages'));
    }

    // The table of Symfony's Doctrine transport, as its Connection describes it, created here because the transport's auto_setup is off.
    private function messengerMessages(): Table
    {
        return Table::editor()
            ->setUnquotedName('messenger_messages')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::BIGINT)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('body')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('headers')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('queue_name')->setTypeName(Types::STRING)->setLength(190)->create(),
                Column::editor()->setUnquotedName('created_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
                Column::editor()->setUnquotedName('available_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
                Column::editor()->setUnquotedName('delivered_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('id'))->create(),
            )
            ->setIndexes(
                Index::editor()->setUnquotedColumnNames('queue_name', 'available_at', 'delivered_at', 'id'),
            )
            ->create();
    }
}
