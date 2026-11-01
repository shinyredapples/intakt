<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the diff of the change a round reviews to review_round';
    }

    public function up(Schema $schema): void
    {
        $column = Column::editor()->setUnquotedName('diff')->setTypeName(Types::TEXT)->setDefaultValue('')->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('review_round'), addedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('review_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('diff')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
