<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create budget_item_failure, the failures of items within the last hour';
    }

    public function up(Schema $schema): void
    {
        $table = Table::editor()
            ->setUnquotedName('budget_item_failure')
            ->setColumns(
                Column::editor()->setUnquotedName('position')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('item')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('error')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('failed_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('position'))->create())
            ->setIndexes(
                Index::editor()->setUnquotedName('budget_item_failure_error')->setUnquotedColumnNames('error', 'failed_at')->create(),
            )
            ->create();

        foreach ($this->platform->getCreateTableSQL($table) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('budget_item_failure'));
    }
}
