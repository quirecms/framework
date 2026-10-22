<?php

declare(strict_types=1);

namespace Quire\Fixtures\Hello\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the fixture extension\'s like table, related to content';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quire.quire_fixture_hello_like (id SERIAL PRIMARY KEY, content_id UUID NOT NULL REFERENCES quire.content (id) ON DELETE CASCADE, name VARCHAR(100) NOT NULL)');
        $this->addSql('CREATE INDEX quire_fixture_hello_like_content_idx ON quire.quire_fixture_hello_like (content_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.quire_fixture_hello_like');
    }
}
