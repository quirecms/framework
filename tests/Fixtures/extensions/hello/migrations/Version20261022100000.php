<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the fixture extension\'s visit table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quire_fixture_hello_visit (id SERIAL PRIMARY KEY, name VARCHAR(100) NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire_fixture_hello_visit');
    }
}
