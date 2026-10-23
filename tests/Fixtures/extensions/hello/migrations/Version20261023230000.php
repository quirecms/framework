<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the fixture extension\'s attachment table, which references stored files';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quire.quire_fixture_hello_attachment (id SERIAL PRIMARY KEY, object_id UUID NOT NULL REFERENCES quire.storage_object (id) ON DELETE RESTRICT, name VARCHAR(100) NOT NULL)');
        $this->addSql('CREATE INDEX quire_fixture_hello_attachment_object_idx ON quire.quire_fixture_hello_attachment (object_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.quire_fixture_hello_attachment');
    }
}
