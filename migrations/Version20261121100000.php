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

final class Version20261121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the kept_verdict table of escalation verdicts, one per finding key';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->keptVerdict()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('kept_verdict'));
    }

    private function keptVerdict(): Table
    {
        return Table::editor()
            ->setUnquotedName('kept_verdict')
            ->setColumns(
                Column::editor()->setUnquotedName('finding_key')->setTypeName(Types::STRING)->setLength(255)->create(),
                Column::editor()->setUnquotedName('outcome')->setTypeName(Types::STRING)->setLength(16)->create(),
                Column::editor()->setUnquotedName('reasons')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('round')->setTypeName(Types::STRING)->setLength(36)->create(),
                Column::editor()->setUnquotedName('recorded_at')->setTypeName(Types::DATETIME_IMMUTABLE)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('finding_key'))->create(),
            )
            ->create();
    }
}
