<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\RecordingAppRebuilder;
use App\Tests\Support\ScratchDatabases;
use Doctrine\DBAL\Connection;
use Quire\Backup\BackupContents;
use Quire\Backup\BackupLog;
use Quire\Backup\Backups;
use Quire\Backup\Internal\RestoreChecks;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Storage\StorageDisks;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * quire:backup and quire:restore. The site from backup to restore is in {@see \App\Tests\Acceptance\ContentCoreTest}.
 */
final class BackupsTest extends WebTestCase
{
    use ScratchDatabases;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-backups-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        array_map(unlink(...), false === $files ? [] : $files);
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testABackupFileAppearsOnlyWhenCompleteAndOnlyItsOwnerCanReadIt(): void
    {
        $this->clientForNewDatabase('backup');
        $file = $this->directory.'/site.dump';

        $output = $this->console('quire:backup', ['file' => $file]);

        self::assertStringContainsString('Backed up to '.$file, $output);
        self::assertSame(0o600, fileperms($file) & 0o777);
        self::assertSame([$file], glob($this->directory.'/*'));
        self::assertStringContainsString('already exists', $this->console('quire:backup', ['file' => $file], expectedStatus: 1));

        // When the site was last backed up, e.g. for its health on the admin's Home.
        $last = self::getContainer()->get(BackupLog::class)->last();
        self::assertNotNull($last);
        self::assertSame([true, filesize($file), $file, null], [$last->ok, $last->bytes, $last->target, $last->problem], 'A refused backup isn\'t one.');
        self::assertEqualsWithDelta(time(), $last->finishedAt->getTimestamp(), 60);
        self::assertEquals($last, self::getContainer()->get(BackupLog::class)->lastSuccessful());
    }

    public function testAFailedRestorePutsTheDataFromBeforeBack(): void
    {
        $this->clientForNewDatabase('rollback');
        $rebuilder = new RecordingAppRebuilder(failWith: 'A migration failed.');
        self::getContainer()->set(AppRebuilderInterface::class, $rebuilder);
        $file = $this->backupWith('In the backup');
        $this->writeContent('Added later');

        $output = $this->console('quire:restore', ['file' => $file, '--force' => true], expectedStatus: 1);

        self::assertStringContainsString('the data from before is back in place. A migration failed.', $output);
        self::assertSame(['In the backup', 'Added later'], $this->titles());
        self::assertSame([true, false], $rebuilder->calls, 'The application is rebuilt for the data from before.');
        self::assertFalse(self::getContainer()->get(RestoreChecks::class)->schemaExists(RestoreChecks::REPLACED_SCHEMA));
    }

    public function testRestoringReplacesQuiresDataAndLeavesTheApplicationsAlone(): void
    {
        $this->clientForNewDatabase('shared');
        self::getContainer()->set(AppRebuilderInterface::class, new RecordingAppRebuilder());
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('CREATE TABLE public.tournament (name TEXT)');
        $connection->executeStatement("INSERT INTO public.tournament VALUES ('Spring Cup')");
        $file = $this->backupWith('In the backup');
        $this->writeContent('Added later');
        $connection->executeStatement("INSERT INTO public.tournament VALUES ('Summer Cup')");

        $this->console('quire:restore', ['file' => $file], expectedStatus: 1);
        self::assertSame(['In the backup', 'Added later'], $this->titles(), 'Existing data is only replaced with --force.');

        $this->console('quire:restore', ['file' => $file, '--force' => true]);

        self::assertSame(['In the backup'], $this->titles());
        self::assertSame(['Spring Cup', 'Summer Cup'], $connection->fetchFirstColumn('SELECT name FROM public.tournament ORDER BY name'), 'The application\'s data is neither backed up nor replaced.');
        self::assertSame(
            $this->countRows("SELECT count(*) FROM pg_tables WHERE schemaname = 'quire'"),
            self::getContainer()->get(Backups::class)->inspect($file)->tables,
            'Quire\'s and the fixture extension\'s tables only, not the application\'s.',
        );
    }

    public function testRestoringIsRefusedWhenTheApplicationDependsOnQuiresTables(): void
    {
        $this->clientForNewDatabase('dependent');
        $file = $this->backupWith('In the backup');
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('CREATE TABLE public.tournament (page_id UUID REFERENCES quire.content (id))');

        $output = $this->console('quire:restore', ['file' => $file, '--force' => true], expectedStatus: 1);

        self::assertStringContainsString('the foreign key tournament_page_id_fkey on tournament', $output);
    }

    public function testABackupDescribesTheCodeItNeeds(): void
    {
        $this->clientForNewDatabase('describe');
        $backup = self::getContainer()->get(Backups::class)->inspect($this->backupWith('In the backup'));

        self::assertContains(\Quire\Migrations\Version20261021120000::class, $backup->migrations);
        self::assertContains(\Quire\Fixtures\Hello\Migrations\Version20261022150000::class, $backup->migrations);
        self::assertSame([], $backup->enabledExtensions, 'The test configuration pins the extensions, so the database lists none.');
        self::assertSame([], self::getContainer()->get(Backups::class)->problems($backup));

        $output = $this->console('quire:restore', ['file' => $this->directory.'/in-the-backup.dump', '--dry-run' => true]);
        self::assertStringContainsString(\sprintf('Migrations %d', \count($backup->migrations)), $output);
        self::assertCount($this->countRows('SELECT count(*) FROM quire.migration_versions'), $backup->migrations);
        self::assertStringContainsString('It would replace the current data (1 content item).', $output);
    }

    public function testBackupsOfNewerCodeAreRefused(): void
    {
        self::bootKernel();
        $backup = $this->contents(migrations: [
            \Quire\Migrations\Version20261021120000::class,
            'Quire\Migrations\Version29990101000000',
            'Quire\Fixtures\Hello\Migrations\Version29990101000000',
            'Acme\Gone\Migrations\Version20261021120000',
        ]);

        self::assertSame([
            'The backup was made with a newer version of Quire: it has migrations this code doesn\'t have (Version29990101000000). Update Quire first.',
            'The backup was made with a newer version of the extension "quire-fixture-hello": it has migrations this code doesn\'t have (Version29990101000000). Update the extension "quire-fixture-hello" first.',
        ], self::getContainer()->get(RestoreChecks::class)->problems($backup), 'Migrations of extensions that aren\'t enabled, like "Acme\Gone", don\'t matter.');
    }

    public function testBackupsWithExtensionsThatArentInstalledAreRefused(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $checks = new RestoreChecks(
            $container->get(Connection::class),
            $container->get(InstalledExtensions::class),
            new class implements ExtensionStateStoreInterface {
                public function enabledIds(): array
                {
                    return [];
                }

                public function readOnlyReason(): ?string
                {
                    return null;
                }

                public function enable(array $ids): void
                {
                }

                public function disable(array $ids): void
                {
                }
            },
            $container->get(StorageDisks::class),
            $container->getParameter('kernel.project_dir'),
            'test',
        );

        self::assertSame([], $checks->problems($this->contents(extensions: ['quire-fixture-hello'])), 'An installed extension the backup has enabled is fine.');
        self::assertSame(
            ['The backup has the extension "acme-gone" enabled, which isn\'t installed here. Install it with Composer first.'],
            $checks->problems($this->contents(extensions: ['acme-gone', 'quire-fixture-hello'])),
        );
    }

    public function testFilesThatArentBackupsOfQuireAreRefused(): void
    {
        $this->clientForNewDatabase('foreign');
        file_put_contents($this->directory.'/notes.txt', 'Not a backup');

        self::assertStringContainsString('isn\'t a backup made by quire:backup', $this->console('quire:restore', ['file' => $this->directory.'/notes.txt'], expectedStatus: 1));
        self::assertStringContainsString('Can\'t read', $this->console('quire:restore', ['file' => $this->directory.'/missing.dump'], expectedStatus: 1));
    }

    private function backupWith(string $title): string
    {
        $this->writeContent($title);
        $file = \sprintf('%s/%s.dump', $this->directory, strtolower(str_replace(' ', '-', $title)));
        self::getContainer()->get(Backups::class)->create($file);

        return $file;
    }

    private function writeContent(string $title): void
    {
        self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', $title));
    }

    /**
     * @return list<string>
     */
    private function titles(): array
    {
        /** @var list<string> $titles */
        $titles = self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT title FROM quire.content ORDER BY id');

        return $titles;
    }

    /**
     * @param list<string> $migrations
     * @param list<string> $extensions
     */
    private function contents(array $migrations = [], array $extensions = []): BackupContents
    {
        return new BackupContents(null, 'app', '18.0', 8, $migrations, $extensions);
    }

    private function countRows(string $sql): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne($sql);
        \assert(is_numeric($count));

        return (int) $count;
    }
}
