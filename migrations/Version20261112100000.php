<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Bridge\Doctrine\Types\UuidType;

final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the round_tool_call table, one row per tool call the review server answered, for the session summary';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->roundToolCall()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('round_tool_call'));
    }

    private function roundToolCall(): Table
    {
        return Table::editor()
            ->setUnquotedName('round_tool_call')
            ->setColumns(
                Column::editor()->setUnquotedName('position')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('round_id')->setTypeName(UuidType::NAME)->create(),
                Column::editor()->setUnquotedName('tool')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('file_read')->setTypeName(Types::TEXT)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('position'))->create())
            ->setIndexes(
                Index::editor()->setUnquotedName('round_tool_call_round')->setUnquotedColumnNames('round_id')->create(),
            )
            ->setForeignKeyConstraints(
                ForeignKeyConstraint::editor()
                    ->setUnquotedReferencingColumnNames('round_id')
                    ->setUnquotedReferencedTableName('review_round')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->create();
    }
}
