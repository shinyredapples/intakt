<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep on watcher_started_round the steering labels its round started with';
    }

    // Nullable for good, because a rollback keeps the database and the release before stores its rounds without the column; null reads as no labels (docs/design/02-principles.md, add, don't move, on upgrade).
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('watcher_started_round');
        $labels = Column::editor()->setUnquotedName('labels')->setTypeName(Types::JSON)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, addedColumns: [$labels])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('watcher_started_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('labels')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
