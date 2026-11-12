<?php

declare(strict_types=1);

namespace Quire\Privacy\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the consent log of quire/privacy: each choice, and what visitors were told for each revision of the services';
    }

    public function up(Schema $schema): void
    {
        // What visitors were told: the services' declarations, once per revision. A logged choice
        // points at the one its visitor saw, so it stays proof after the services change.
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.quire_privacy_declaration (
                revision VARCHAR(64) PRIMARY KEY,
                services JSONB NOT NULL,
                first_seen_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);

        // Each choice a visitor made: its random ID (the cookie's, never an account or an address),
        // the answer per service, and when. Kept for "quire-privacy.log_months".
        $this->addSql(<<<'SQL'
            CREATE TABLE quire.quire_privacy_consent (
                id UUID PRIMARY KEY DEFAULT uuidv7(),
                consent_id CHAR(32) NOT NULL,
                revision VARCHAR(64) NOT NULL REFERENCES quire.quire_privacy_declaration (revision),
                answers JSONB NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
        $this->addSql('CREATE INDEX quire_privacy_consent_consent_id ON quire.quire_privacy_consent (consent_id, created_at)');
        $this->addSql('CREATE INDEX quire_privacy_consent_created_at ON quire.quire_privacy_consent (created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE quire.quire_privacy_consent');
        $this->addSql('DROP TABLE quire.quire_privacy_declaration');
    }
}
