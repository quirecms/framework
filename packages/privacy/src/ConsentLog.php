<?php

declare(strict_types=1);

namespace Quire\Privacy;

use Doctrine\DBAL\Connection;
use Quire\Database\DatabaseSchema;

/**
 * Proof of consent: each choice a visitor made, by the random ID their browser keeps (never their
 * account or address), with what they were told when they made it. Kept for "quire-privacy.log_months".
 *
 * @api
 */
final readonly class ConsentLog
{
    private const string CONSENT = DatabaseSchema::NAME.'.quire_privacy_consent';
    private const string DECLARATION = DatabaseSchema::NAME.'.quire_privacy_declaration';

    public function __construct(private Connection $connection, private Services $services)
    {
    }

    /**
     * Records a choice. Only answers about the services as they are now count: an unknown service, or a
     * "yes" to another revision of one, isn't what the visitor was shown, so it's refused.
     *
     * @param array<string, string|false> $answers By service ID: the revision allowed, or false
     *
     * @throws \InvalidArgumentException When it isn't a choice of this site's visitors
     */
    public function record(string $consentId, array $answers): void
    {
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $consentId)) {
            throw new \InvalidArgumentException('A choice\'s ID is 32 hexadecimal characters.');
        }

        if ([] === $answers || \count($answers) > \count($this->services->askedAbout())) {
            throw new \InvalidArgumentException('A choice answers for the site\'s services.');
        }

        foreach ($answers as $id => $answer) {
            $service = $this->services->get($id);

            if (!$service instanceof Service || !$service->category->needsConsent() || (false !== $answer && $service->revision() !== $answer)) {
                throw new \InvalidArgumentException(\sprintf('"%s" isn\'t a service visitors are asked about, as it is now.', $id));
            }
        }

        $revision = $this->services->revision();
        $this->connection->executeStatement(
            'INSERT INTO '.self::DECLARATION.' (revision, services) VALUES (?, ?) ON CONFLICT (revision) DO NOTHING',
            [$revision, json_encode($this->declarations(), \JSON_THROW_ON_ERROR)],
        );
        $this->connection->executeStatement(
            'INSERT INTO '.self::CONSENT.' (consent_id, revision, answers) VALUES (?, ?, ?)',
            [$consentId, $revision, json_encode($answers, \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * A visitor's choices, newest first, each with what they were told: for a visitor who asks what
     * they agreed to (they find the ID in "Cookie settings").
     *
     * @return list<array{at: \DateTimeImmutable, answers: array<string, string|false>, services: list<array<string, mixed>>}>
     */
    public function of(string $consentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.created_at, c.answers, d.services FROM '.self::CONSENT.' c JOIN '.self::DECLARATION.' d USING (revision) WHERE c.consent_id = ? ORDER BY c.created_at DESC, c.id DESC',
            [$consentId],
        );

        return array_map(static function (array $row): array {
            /** @var array<string, string|false> $answers */
            $answers = json_decode(\is_string($row['answers']) ? $row['answers'] : '{}', true, flags: \JSON_THROW_ON_ERROR);
            /** @var list<array<string, mixed>> $services */
            $services = json_decode(\is_string($row['services']) ? $row['services'] : '[]', true, flags: \JSON_THROW_ON_ERROR);

            return ['at' => new \DateTimeImmutable(\is_string($row['created_at']) ? $row['created_at'] : 'now'), 'answers' => $answers, 'services' => $services];
        }, $rows);
    }

    /**
     * How visitors answered since a time, by service: counts only.
     *
     * @return array<string, array{allowed: int, refused: int}>
     */
    public function totals(\DateTimeImmutable $since): array
    {
        /** @var list<array{service: string, allowed: int|string, refused: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.key AS service, count(*) FILTER (WHERE jsonb_typeof(a.value) = \'string\') AS allowed, count(*) FILTER (WHERE a.value = \'false\'::jsonb) AS refused
             FROM '.self::CONSENT.' c, jsonb_each(c.answers) a WHERE c.created_at >= ? GROUP BY a.key',
            [$since->format(\DATE_ATOM)],
        );
        $totals = [];

        foreach ($rows as $row) {
            $totals[$row['service']] = ['allowed' => (int) $row['allowed'], 'refused' => (int) $row['refused']];
        }

        return $totals;
    }

    /**
     * Removes choices made before a time, and what visitors were told that no remaining choice needs.
     *
     * @return int The choices removed
     */
    public function purge(\DateTimeImmutable $before): int
    {
        $removed = (int) $this->connection->executeStatement('DELETE FROM '.self::CONSENT.' WHERE created_at < ?', [$before->format(\DATE_ATOM)]);
        $this->connection->executeStatement('DELETE FROM '.self::DECLARATION.' d WHERE NOT EXISTS (SELECT 1 FROM '.self::CONSENT.' c WHERE c.revision = d.revision) AND d.revision <> ?', [$this->services->revision()]);

        return $removed;
    }

    /**
     * What visitors are told now, as the log keeps it.
     *
     * @return list<array<string, mixed>>
     */
    private function declarations(): array
    {
        return array_map(static fn (Service $service): array => [
            'id' => $service->id,
            'revision' => $service->revision(),
            'label' => $service->label,
            'category' => $service->category->value,
            'vendor' => $service->vendor,
            'purpose' => $service->purpose,
            'cookies' => array_map(static fn (Cookie $cookie): array => ['name' => $cookie->name, 'lifetime' => $cookie->lifetime, 'purpose' => $cookie->purpose], $service->cookies),
            'hosts' => $service->hosts,
            'retention' => $service->retention,
            'policy' => $service->policyUrl,
        ], $this->services->askedAbout());
    }
}
