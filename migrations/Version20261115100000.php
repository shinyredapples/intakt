<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the review table of projects flagged as replay and add to review_round whether it is a replay';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->replayProject()) as $sql) {
            $this->addSql($sql);
        }

        $column = Column::editor()->setUnquotedName('replay')->setTypeName(Types::BOOLEAN)->setDefaultValue(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('review_round'), addedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('review_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('replay')])) as $sql) {
            $this->addSql($sql);
        }

        $this->addSql($this->platform->getDropTableSQL('review_replay_project'));
    }

    private function replayProject(): Table
    {
        return Table::editor()
            ->setUnquotedName('review_replay_project')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'))->create(),
            )
            ->create();
    }
}
