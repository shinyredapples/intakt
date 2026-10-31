<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the verdict identity a round was started on to review_round';
    }

    public function up(Schema $schema): void
    {
        $identity = Column::editor()->setUnquotedName('verdict_identity')->setTypeName(Types::TEXT)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('review_round'), addedColumns: [$identity])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $identity = $schema->getTable('review_round')->getColumn('verdict_identity');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('review_round'), droppedColumns: [$identity])) as $sql) {
            $this->addSql($sql);
        }
    }
}
