<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the build table of parked tasks with the question each one waits on';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->parkedTask()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('build_parked_task'));
    }

    private function parkedTask(): Table
    {
        return Table::editor()
            ->setUnquotedName('build_parked_task')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('task')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('question')->setTypeName(Types::JSON)->create(),
                Column::editor()->setUnquotedName('choices')->setTypeName(Types::JSON)->create(),
                Column::editor()->setUnquotedName('recommendation')->setTypeName(Types::JSON)->create(),
                Column::editor()->setUnquotedName('parked_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(
                    UnqualifiedName::unquoted('project'),
                    UnqualifiedName::unquoted('task'),
                )->create(),
            )
            ->create();
    }
}
