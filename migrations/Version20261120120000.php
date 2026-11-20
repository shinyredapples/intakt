<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120120000 extends AbstractMigration
{
    private const array COLUMNS = ['agent_session_id', 'interruptions'];

    public function getDescription(): string
    {
        return 'Add the agent session id and the interruptions of a reviewer to round_reviewer; the interrupted state needs no schema change';
    }

    public function up(Schema $schema): void
    {
        $columns = [
            Column::editor()->setUnquotedName('agent_session_id')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create(),
            Column::editor()->setUnquotedName('interruptions')->setTypeName(Types::INTEGER)->setDefaultValue(0)->create(),
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
