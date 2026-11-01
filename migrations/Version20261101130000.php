<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the findings a round resolved, kept for its indexing into project memory, to review_round';
    }

    public function up(Schema $schema): void
    {
        // Nullable rather than defaulted, because DBAL's MySQL platform leaves a default on a text column out of the column declaration, so a declared default would not reach the MySQL schema.
        $column = Column::editor()->setUnquotedName('resolved_findings')->setTypeName(Types::TEXT)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('review_round'), addedColumns: [$column])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('review_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('resolved_findings')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
