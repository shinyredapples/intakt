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

final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the review_round and round_reviewer tables of the review round state machines';
    }

    public function up(Schema $schema): void
    {
        foreach ([$this->reviewRound(), $this->roundReviewer()] as $table) {
            foreach ($this->platform->getCreateTableSQL($table) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('round_reviewer'));
        $this->addSql($this->platform->getDropTableSQL('review_round'));
    }

    private function reviewRound(): Table
    {
        return Table::editor()
            ->setUnquotedName('review_round')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(UuidType::NAME)->create(),
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('checkout_root')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('task')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('state')->setTypeName(Types::STRING)->setLength(16)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('id'))->create(),
            )
            ->create();
    }

    private function roundReviewer(): Table
    {
        return Table::editor()
            ->setUnquotedName('round_reviewer')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(UuidType::NAME)->create(),
                Column::editor()->setUnquotedName('round_id')->setTypeName(UuidType::NAME)->create(),
                Column::editor()->setUnquotedName('role_name')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('role_prompt')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('tier')->setTypeName(Types::STRING)->setLength(16)->create(),
                Column::editor()->setUnquotedName('state')->setTypeName(Types::STRING)->setLength(16)->create(),
                Column::editor()->setUnquotedName('verdict')->setTypeName(Types::TEXT)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('failure')->setTypeName(Types::STRING)->setLength(255)->setNotNull(false)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('id'))->create(),
            )
            ->setIndexes(
                Index::editor()->setUnquotedName('round_reviewer_round')->setUnquotedColumnNames('round_id')->create(),
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
