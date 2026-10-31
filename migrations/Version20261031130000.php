<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UuidType;

final class Version20261031130000 extends AbstractMigration
{
    private const array COLUMNS = ['restarts', 'conversation_id'];

    public function getDescription(): string
    {
        return 'Add the restarts of a stopped worker\'s reviewer and the conversation of its current attempt to round_reviewer';
    }

    public function up(Schema $schema): void
    {
        $columns = [
            Column::editor()->setUnquotedName('restarts')->setTypeName(Types::INTEGER)->setDefaultValue(0)->create(),
            Column::editor()->setUnquotedName('conversation_id')->setTypeName(UuidType::NAME)->setNotNull(false)->create(),
        ];

        foreach ($this->platform->getAlterTableSQL(new TableDiff($schema->getTable('round_reviewer'), addedColumns: $columns)) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('round_reviewer');
        $columns = array_map($table->getColumn(...), self::COLUMNS);

        foreach ($this->platform->getAlterTableSQL(new TableDiff($table, droppedColumns: $columns)) as $sql) {
            $this->addSql($sql);
        }
    }
}
