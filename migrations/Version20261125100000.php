<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep on review_round when the forge took its verdict';
    }

    // Nullable for good, because a rollback keeps the database and the release before stores its rounds without the column; null reads as not published, so a round judged before this migration stays in review until its next publication (docs/design/02-principles.md, add, don't move, on upgrade).
    public function up(Schema $schema): void
    {
        $table = $schema->getTable('review_round');
        $publishedAt = Column::editor()->setUnquotedName('published_at')->setTypeName(Types::DATETIME_IMMUTABLE)->setNotNull(false)->create();

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, addedColumns: [$publishedAt])) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('review_round');

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: [$table->getColumn('published_at')])) as $sql) {
            $this->addSql($sql);
        }
    }
}
