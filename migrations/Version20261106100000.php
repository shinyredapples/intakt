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

final class Version20261106100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the budget table of item failures, which the install-wide back-off compares by error';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->itemFailure()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('budget_item_failure'));
    }

    private function itemFailure(): Table
    {
        return Table::editor()
            ->setUnquotedName('budget_item_failure')
            ->setColumns(
                Column::editor()->setUnquotedName('position')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create(),
                Column::editor()->setUnquotedName('item')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('error_key')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('recorded_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('position'))->create())
            ->setIndexes(
                Index::editor()->setUnquotedName('budget_item_failure_error')->setUnquotedColumnNames('error_key')->create(),
            )
            ->create();
    }
}
