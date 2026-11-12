<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deliberately broken, to show the upgrade check failing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE no_such_table SET broken = 1');
    }
}
