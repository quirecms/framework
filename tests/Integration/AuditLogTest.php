<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Audit\AuditQuery;
use Quire\Audit\Event\AuditEntryRecorded;
use Quire\Audit\Origin;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Quire\Settings\Event\SettingChanged;
use Quire\Settings\Settings;
use Quire\User\AccountSettings;
use Quire\User\CreateUser;
use Quire\User\UpdateUser;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The audit log (D-21): core's actions recorded from its events, with the console commands that do the
 * same, repeated saves in one entry, no secrets, and the API extensions use.
 */
final class AuditLogTest extends KernelTestCase
{
    private Connection $connection;

    private AuditLog $log;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->log = self::getContainer()->get(AuditLog::class);
        $this->log->reset();
        // Tests that commit leave entries behind.
        $this->connection->executeStatement('DELETE FROM quire.audit_entry');
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testContentActionsAreRecordedWithTheirCommands(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', 'Audited'));
        $post = $writer->update(new UpdateContent($post->id, $post->version)->title('Audited again'));
        $post = $writer->update(new UpdateContent($post->id, $post->version)->body('More.'));
        $post = $writer->transition($post->id, $post->version, Status::PUBLISH);
        $post = $writer->trash($post->id, $post->version);
        $post = $writer->restore($post->id);
        $post = $writer->trash($post->id, $post->version);
        $writer->purge($post->id);

        $entries = $this->entries(new AuditQuery(subjectId: $post->id));

        self::assertSame(['content.created', 'content.updated', 'content.transitioned', 'content.trashed', 'content.restored', 'content.trashed', 'content.purged'], array_column($entries, 'action'));
        self::assertSame([null, null, 'bin/console quire:content:transition '.$post->id.' publish', 'bin/console quire:content:delete '.$post->id, 'bin/console quire:content:restore '.$post->id, 'bin/console quire:content:delete '.$post->id, 'bin/console quire:content:delete '.$post->id.' --purge --force'], array_column($entries, 'command'));
        self::assertSame(2, $entries[1]['repeats'], 'Saving again soon after updates the entry.');
        self::assertSame(['title', 'body'], $entries[1]['details']['changed'], 'What changed in either save.');
        // PostgreSQL orders the keys of JSON objects its own way.
        self::assertEquals(['transition' => 'publish', 'from' => Status::DRAFT, 'to' => Status::PUBLISHED, 'type' => 'post'], $entries[2]['details']);
        self::assertSame('Audited again', $entries[6]['subject_label'], 'Named as it was, after it\'s gone.');
        self::assertSame([Origin::Console], array_values(array_unique(array_column($entries, 'origin'), \SORT_REGULAR)), 'Not in a web request.');
        self::assertCount(1, array_unique(array_column($entries, 'correlation_id')), 'One process: one group.');
    }

    public function testPublishedChangesAreOneEntry(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $pending = self::getContainer()->get(PendingRevisionWriter::class);
        $post = $writer->create(new CreateContent('post', 'Live'));
        $post = $writer->transition($post->id, $post->version, Status::PUBLISH);
        $change = $pending->create($post->id);
        $change = $pending->update(new UpdatePendingRevision($change->id, $change->version)->title('Live, changed'));
        $change = $pending->update(new UpdatePendingRevision($change->id, $change->version)->body('Autosaved.'));
        $writer->publishPendingRevision($change->id, $change->version);

        $entries = \array_slice($this->entries(new AuditQuery(subjectId: $post->id)), 2);

        self::assertSame(['content.changes_saved', 'content.changes_published'], array_column($entries, 'action'), 'No "edited" entry next to it.');
        self::assertSame(3, $entries[0]['repeats'], 'Created and saved twice: one entry.');
        self::assertSame('bin/console quire:content:pending '.$post->id.' --publish='.$change->id, $entries[1]['command']);
        self::assertSame(['title', 'body'], $entries[1]['details']['changed']);
    }

    public function testUserActionsAreRecordedWithoutPersonalDataOrPasswords(): void
    {
        $users = self::getContainer()->get(UserWriter::class);
        $ada = $users->create(new CreateUser('Ada', 'ada@example.com', username: 'ada', roles: ['editor']));
        $users->update(new UpdateUser($ada->id)->email('ada@example.org'));
        $users->setRoles($ada->id, ['member']);
        $users->changePassword($ada->id, 'correct-horse-42');
        $users->suspend($ada->id);
        $users->activate($ada->id);
        $users->delete($ada->id);

        $entries = $this->entries(new AuditQuery(subjectId: $ada->id));

        self::assertSame(['user.created', 'user.updated', 'user.roles_changed', 'user.password_changed', 'user.suspended', 'user.activated', 'user.deleted'], array_column($entries, 'action'));
        self::assertSame([
            'bin/console quire:user:create ada@example.com --name=Ada --username=ada --no-password --role=editor',
            null,
            'bin/console quire:user:role ada --add=member --remove=editor',
            'bin/console quire:user:password ada',
            'bin/console quire:user:suspend ada',
            'bin/console quire:user:activate ada',
            'bin/console quire:user:delete ada --keep-content',
        ], array_column($entries, 'command'));
        self::assertSame(['changed' => ['email']], $entries[1]['details'], 'Which, not the new value.');
        self::assertStringNotContainsString('correct-horse', (string) json_encode($entries));
        self::assertStringNotContainsString('ada@example.org', (string) json_encode($entries));
    }

    public function testTheActorsNameOutlivesThem(): void
    {
        $users = self::getContainer()->get(UserWriter::class);
        $grace = $users->create(new CreateUser('Grace'));
        $entry = $this->log->record('acme.thing_done', actorId: $grace->id);
        $users->delete($grace->id);

        $after = $this->log->get((string) $entry?->id);
        self::assertSame([null, 'Grace'], [$after?->actorId, $after?->actorName]);
    }

    public function testRolesAndPermissionsAreRecordedAsWhatTheyMean(): void
    {
        $roles = self::getContainer()->get(SiteRoles::class);
        $permissions = self::getContainer()->get(Permissions::class);
        $roles->create('translator', 'Translator');
        $permissions->grant('translator', ['content.post.edit_any', 'content.page.edit_any']);
        $permissions->revoke('translator', ['content.page.edit_any']);
        $permissions->revoke('editor', ['content.post.publish']);
        $roles->relabel('translator', 'Übersetzer');
        $roles->delete('translator');

        $entries = $this->entries(new AuditQuery(action: 'role.'));

        self::assertSame(['role.created', 'role.abilities_changed', 'role.abilities_changed', 'role.abilities_changed', 'role.renamed', 'role.deleted'], array_column($entries, 'action'));
        self::assertSame([
            'bin/console quire:permissions:create-role translator Translator',
            'bin/console quire:permissions:grant translator content.post.edit_any content.page.edit_any',
            'bin/console quire:permissions:revoke translator content.page.edit_any',
            'bin/console quire:permissions:revoke editor content.post.publish',
            null,
            'bin/console quire:permissions:delete-role translator --force',
        ], array_column($entries, 'command'));
        self::assertSame(['granted' => [], 'revoked' => ['content.post.edit_any']], $entries[5]['details'], 'What it could do when it was deleted.');
        self::assertSame('editor', $entries[3]['subject_id']);
        self::assertSame([], $this->entries(new AuditQuery(action: 'setting.')), 'Not as settings, which store them.');
    }

    public function testSettingsAreRecordedWithoutSecrets(): void
    {
        $settings = self::getContainer()->get(Settings::class);
        $settings->set('site.title', 'It\'s audited');
        $settings->set('quire.content.check_levels', ['core.alt-text' => 'error']);
        $settings->set(AccountSettings::MAIL_FROM, 'News <news@example.com>');
        $settings->restoreDefault(AccountSettings::MAIL_FROM);
        // A setting nobody declares any more is treated as sensitive.
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new SettingChanged('acme.api_key', 'old-secret', 'new-secret'));

        $entries = $this->entries(new AuditQuery(action: 'setting.'));

        self::assertSame([
            "bin/console quire:settings:set site.title 'It'\\''s audited'",
            'bin/console quire:settings:set quire.content.check_levels \'{"core.alt-text":"error"}\'',
            "bin/console quire:settings:set quire.mail.from 'News <news@example.com>'",
            'bin/console quire:settings:set quire.mail.from --default',
            null,
        ], array_column($entries, 'command'));
        self::assertEquals(['from' => 'Quire', 'to' => 'It\'s audited'], $entries[0]['details']);
        self::assertSame(['sensitive' => true], $entries[4]['details']);
        self::assertStringNotContainsString('secret', (string) json_encode($entries));
    }

    public function testTheRecordedCommandsDoTheSame(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $draft = $writer->create(new CreateContent('post', 'Twice'));
        $writer->transition($draft->id, $draft->version, Status::PUBLISH);
        $self = self::getContainer()->get(UserWriter::class)->create(new CreateUser('Kim', 'kim@example.com', username: 'kim'));
        self::getContainer()->get(UserWriter::class)->setRoles($self->id, ['editor']);
        self::getContainer()->get(Settings::class)->set('site.description', 'A site, "quoted"');
        $commands = array_filter(array_column($this->entries(new AuditQuery(since: new \DateTimeImmutable('-1 minute'))), 'command'), static fn (?string $command): bool => null !== $command && !str_contains($command, 'quire:user:create'));

        // Back to how it was, then the commands do it again.
        $other = $writer->create(new CreateContent('post', 'Twice'));
        $this->connection->executeStatement("UPDATE quire.content SET status = 'draft' WHERE id = ?", [$draft->id]);
        $this->connection->executeStatement("UPDATE quire.app_user SET roles = '{}' WHERE id = ?", [$self->id]);
        self::getContainer()->get(Settings::class)->set('site.description', '');
        unset($other);

        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);

        foreach ($commands as $command) {
            $output = new BufferedOutput();
            self::assertSame(0, $application->run(new StringInput(substr($command, \strlen('bin/console ')).' --no-interaction'), $output), $command."\n".$output->fetch());
        }

        self::assertSame(Status::PUBLISHED, $this->connection->fetchOne('SELECT status FROM quire.content WHERE id = ?', [$draft->id]));
        self::assertSame('{editor}', $this->connection->fetchOne('SELECT roles FROM quire.app_user WHERE id = ?', [$self->id]));
        self::assertSame('A site, "quoted"', self::getContainer()->get(Settings::class)->get('site.description'));
    }

    public function testExtensionsRecordTheirActions(): void
    {
        $recorded = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(AuditEntryRecorded::class, static function (AuditEntryRecorded $event) use (&$recorded): void {
            $recorded[] = $event->entry->action;
        });

        $first = $this->log->record('acme-shop.order_refunded', 'acme-shop.order', '1042', 'Order 1042', ['lines' => ['a']], 'bin/console acme-shop:refund 1042', coalesce: true);
        $again = $this->log->record('acme-shop.order_refunded', 'acme-shop.order', '1042', 'Order 1042', ['lines' => ['b'], 'total' => 5], coalesce: true);

        self::assertNotNull($first);
        self::assertSame([$first->id, 2, ['lines' => ['a', 'b'], 'total' => 5]], [$again?->id, $again?->repeats, $again?->details], 'Lists are joined, other details replaced.');
        self::assertSame(['acme-shop.order_refunded', 'acme-shop.order_refunded'], $recorded);
        self::assertSame([$again?->id], array_map(static fn (AuditEntry $entry): string => $entry->id, $this->log->recordedNow()));

        self::assertNull($this->log->record('acme-shop.broken', details: ['amount' => \NAN]), 'An entry that can\'t be written doesn\'t fail the action.');
        self::assertCount(1, $this->log->find(new AuditQuery(action: 'acme-shop.'))->entries);
    }

    public function testOldEntriesArePruned(): void
    {
        $old = $this->log->record('acme.old');
        $this->connection->executeStatement("UPDATE quire.audit_entry SET occurred_at = now() - INTERVAL '400 days', last_at = now() - INTERVAL '400 days' WHERE id = ?", [$old?->id]);
        $new = $this->log->record('acme.new');

        self::assertSame(1, $this->log->prune(new \DateTimeImmutable('-365 days')));
        self::assertNull($this->log->get((string) $old?->id));
        self::assertNotNull($this->log->get((string) $new?->id));
    }

    /**
     * @return list<array{action: string, command: ?string, repeats: int, details: array<string, mixed>, subject_id: ?string, subject_label: ?string, origin: Origin, correlation_id: string}> Oldest first
     */
    private function entries(AuditQuery $query): array
    {
        return array_reverse(array_map(static fn (AuditEntry $entry): array => [
            'action' => $entry->action,
            'command' => $entry->command,
            'repeats' => $entry->repeats,
            'details' => $entry->details,
            'subject_id' => $entry->subjectId,
            'subject_label' => $entry->subjectLabel,
            'origin' => $entry->origin,
            'correlation_id' => $entry->correlationId,
        ], $this->log->find($query, perPage: 500)->entries));
    }
}
