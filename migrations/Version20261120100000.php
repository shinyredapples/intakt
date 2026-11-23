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

final class Version20261120100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the work intake tables of the listing validator per project, the open issues and pull requests the last complete listing showed, and the tasks that failed for good';
    }

    public function up(Schema $schema): void
    {
        foreach ([$this->validator(), $this->issue(), $this->failure()] as $table) {
            foreach ($this->platform->getCreateTableSQL($table) as $sql) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('work_source_failure'));
        $this->addSql($this->platform->getDropTableSQL('work_source_issue'));
        $this->addSql($this->platform->getDropTableSQL('work_source_validator'));
    }

    private function validator(): Table
    {
        return Table::editor()
            ->setUnquotedName('work_source_validator')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('validator')->setTypeName(Types::TEXT)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'))->create(),
            )
            ->create();
    }

    private function issue(): Table
    {
        return Table::editor()
            ->setUnquotedName('work_source_issue')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('number')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('title')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('labels')->setTypeName(Types::JSON)->create(),
                Column::editor()->setUnquotedName('author')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('updated_at')->setTypeName(Types::STRING)->setLength(26)->create(),
                Column::editor()->setUnquotedName('milestone_due')->setTypeName(Types::STRING)->setLength(26)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('pull_request')->setTypeName(Types::BOOLEAN)->create(),
                Column::editor()->setUnquotedName('waits_for')->setTypeName(Types::JSON)->create(),
                Column::editor()->setUnquotedName('unreadable_waits')->setTypeName(Types::JSON)->create(),
                Column::editor()->setUnquotedName('closed_by')->setTypeName(Types::JSON)->create(),
                Column::editor()->setUnquotedName('trusted')->setTypeName(Types::BOOLEAN)->setNotNull(false)->create(),
                Column::editor()->setUnquotedName('vouched_labels')->setTypeName(Types::JSON)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'), UnqualifiedName::unquoted('number'))->create(),
            )
            ->create();
    }

    private function failure(): Table
    {
        return Table::editor()
            ->setUnquotedName('work_source_failure')
            ->setColumns(
                Column::editor()->setUnquotedName('project')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('number')->setTypeName(Types::INTEGER)->create(),
                Column::editor()->setUnquotedName('reason')->setTypeName(Types::TEXT)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('project'), UnqualifiedName::unquoted('number'))->create(),
            )
            ->create();
    }
}
