<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the failures in a row of a head waiting in watcher_pending_head';
    }

    public function up(Schema $schema): void
    {
        $column = Column::editor()->setUnquotedName('failures')->setTypeName(Types::INTEGER)->setDefaultValue(0)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('watcher_pending_head'), addedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('watcher_pending_head');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('failures')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
