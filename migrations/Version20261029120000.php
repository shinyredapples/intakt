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

final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the reviewer_conversation table of the Agent message store';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getCreateTableSQL($this->conversation()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('reviewer_conversation'));
    }

    // The columns the Agent's DBAL message store reads and writes.
    private function conversation(): Table
    {
        return Table::editor()
            ->setUnquotedName('reviewer_conversation')
            ->setColumns(
                Column::editor()->setUnquotedName('id')->setTypeName(Types::STRING)->setLength(36)->create(),
                Column::editor()->setUnquotedName('version')->setTypeName(Types::STRING)->setLength(36)->create(),
                Column::editor()->setUnquotedName('messages')->setTypeName(Types::TEXT)->create(),
                Column::editor()->setUnquotedName('updated_at')->setTypeName(Types::INTEGER)->create(),
            )
            ->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(UnqualifiedName::unquoted('id'))->create(),
            )
            ->create();
    }
}
