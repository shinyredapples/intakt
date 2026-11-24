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

final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of the branches branch cleanup warned about, one row per project and branch';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->warning()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('branch_cleanup_warning'));
    }

    private function warning(): Table
    {
        return Table::editor()
            ->setUnquotedName('branch_cleanup_warning')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('branch')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('head')->setTypeName(Types::STRING)->setLength(64)->create(),
                Column::editor()->setUnquotedName('warned_at')->setTypeName(Types::STRING)->setLength(26)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'), UnqualifiedName::unquoted('branch'))->create(),
            )
            ->create();
    }
}
