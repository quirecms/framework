<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\RecordingAppRebuilder;
use App\Tests\Support\ScratchDatabases;
use AsyncAws\S3\S3Client;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Quire\Backup\BackupFiles;
use Quire\Backup\BackupLog;
use Quire\Backup\Backups;
use Quire\Backup\Internal\PostgresTools;
use Quire\Backup\Internal\RestoreChecks;
use Quire\Backup\Internal\TarReader;
use Quire\Content\ContentRenderer;
use Quire\Extension\Boot\OfflineBoot;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Storage\Console\CheckDisksCommand;
use Quire\Storage\Internal\DiskFactory;
use Quire\Storage\Internal\GarbageCollector;
use Quire\Storage\Internal\InstalledDisks;
use Quire\Storage\ObjectStore;
use Quire\Storage\StorageDisks;
use Quire\Storage\StoredObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * Backups of the stored files: copied with the database, checked against their checksums, and restored
 * to wherever the disks are now.
 */
final class BackupFilesTest extends WebTestCase
{
    use ScratchDatabases;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/quire-backup-files-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->clientForNewDatabase('files');
        self::getContainer()->set(AppRebuilderInterface::class, new RecordingAppRebuilder());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testLostFilesComeBackFromTheBackup(): void
    {
        $photo = $this->attach('quire-fixture-hello', 'photo');
        $download = $this->attach('quire-fixture-hello-private', 'download');

        $report = $this->backups()->create($this->directory.'/site.tar');
        self::assertSame([2, 2, []], [$report->objects, $report->copied, $report->missing]);
        self::assertArrayHasKey('files/quire-fixture-hello/'.$photo->key, new TarReader($this->directory.'/site.tar')->entries());

        $this->lose($photo, $download);
        $restored = $this->backups()->restore($this->directory.'/site.tar', static function (string $output): void {});

        self::assertSame([2, 0, [], []], [$restored->restored, $restored->alreadyThere, $restored->corrupt, $restored->missing]);
        self::assertSame($this->contents($photo), stream_get_contents($this->objects()->read($photo)));

        $again = $this->backups()->restore($this->directory.'/site.tar', static function (string $output): void {});
        self::assertSame([0, 2], [$again->restored, $again->alreadyThere], 'Files the disks have are left alone.');
    }

    public function testTheCommandsDescribeTheFiles(): void
    {
        $this->attach('quire-fixture-hello', 'photo');

        $output = $this->console('quire:backup', ['file' => $this->directory.'/site.tar']);
        self::assertStringContainsString('the database, and 1 of 1 stored file(s)', $output);

        $output = $this->console('quire:restore', ['file' => $this->directory.'/site.tar', '--dry-run' => true]);
        self::assertStringContainsString('Stored files 1 (quire-fixture-hello: 1); 1 copied into the backup', $output);
    }

    public function testFilesAreRestoredWhereTheDisksAreNow(): void
    {
        $photo = $this->attach('quire-fixture-hello', 'photo');
        $this->backups()->create($this->directory.'/site.tar');

        // The site's disk is somewhere else now, e.g. after a move to another server or to S3.
        $moved = $this->directory.'/moved';
        $disks = new StorageDisks(
            ['quire-fixture-hello' => ['adapter' => 'local', 'public' => true, 'path' => $moved, 'owner' => 'quire-fixture-hello']],
            new DiskFactory(self::getContainer()->get('router'), new UriSigner('test')),
            new ServiceLocator([]),
        );
        $report = $this->backups($disks)->restore($this->directory.'/site.tar', static function (string $output): void {});

        self::assertSame(1, $report->restored);
        self::assertSame($this->contents($photo), file_get_contents($moved.'/'.$photo->key));
    }

    public function testDamagedFilesAreReportedAndNeverRestored(): void
    {
        $photo = $this->attach('quire-fixture-hello', 'photo');
        $this->disk('quire-fixture-hello')->files->write($photo->key, strrev($this->contents($photo)));

        $report = $this->backups()->create($this->directory.'/site.tar');
        self::assertSame(['quire-fixture-hello:'.$photo->key], $report->corrupt);
        self::assertSame(['quire-fixture-hello:'.$photo->key], $this->backups()->inspect($this->directory.'/site.tar')->missingWhenMade);

        $this->lose($photo);
        $restored = $this->backups()->restore($this->directory.'/site.tar', static function (string $output): void {});

        self::assertSame(['quire-fixture-hello:'.$photo->key], $restored->corrupt, 'Not written under the name of content it doesn\'t have.');
        self::assertSame(['quire-fixture-hello:'.$photo->key], $restored->missing);
        self::assertFalse($this->disk('quire-fixture-hello')->files->fileExists($photo->key));
    }

    public function testAFilesDirectoryCopiesEachFileOnce(): void
    {
        $files = $this->directory.'/files';
        $first = $this->attach('quire-fixture-hello', 'first');
        $one = $this->backups()->create($this->directory.'/one.tar', filesDirectory: $files);
        $second = $this->attach('quire-fixture-hello', 'second');
        $two = $this->backups()->create($this->directory.'/two.tar', filesDirectory: $files);

        self::assertSame([1, 0], [$one->copied, $one->reused]);
        self::assertSame([1, 1], [$two->copied, $two->reused], 'Only the new file.');
        self::assertSame([], array_filter(array_keys(new TarReader($this->directory.'/two.tar')->entries()), static fn (string $name): bool => str_starts_with($name, 'files/')));
        self::assertFileExists($files.'/quire-fixture-hello/'.$first->key);

        self::assertContains('The backup\'s files are in a files directory, not in the backup: pass it with --files-dir.', $this->backups()->problems($this->backups()->inspect($this->directory.'/two.tar')));

        $this->lose($first, $second);
        $restored = $this->backups()->restore($this->directory.'/two.tar', static function (string $output): void {}, $files);
        self::assertSame([2, []], [$restored->restored, $restored->missing]);
    }

    public function testDatabaseOnlyBackupsListTheFilesTheyDontHave(): void
    {
        $photo = $this->attach('quire-fixture-hello', 'photo');
        $report = $this->backups()->create($this->directory.'/site.tar', BackupFiles::None);
        self::assertSame([1, 0, 1], [$report->objects, $report->copied, $report->notCopied]);

        $this->lose($photo);
        $restored = $this->backups()->restore($this->directory.'/site.tar', static function (string $output): void {});
        self::assertSame(['quire-fixture-hello:'.$photo->key], $restored->missing, 'The restore says what\'s not there.');
    }

    public function testBackupsMadeBeforeFilesAreStillRestored(): void
    {
        $this->attach('quire-fixture-hello', 'photo');
        // What quire:backup wrote until now: a pg_dump archive.
        self::getContainer()->get(PostgresTools::class)->run('pg_dump', ['--format=custom', '--schema=quire', '--no-password', '--file='.$this->directory.'/old.dump']);

        $backup = $this->backups()->inspect($this->directory.'/old.dump');
        self::assertSame(['dump', null, ['quire-fixture-hello' => 1]], [$backup->format, $backup->files, $backup->objects]);

        $restored = $this->backups()->restore($this->directory.'/old.dump', static function (string $output): void {});
        self::assertSame([0, []], [$restored->restored, $restored->missing], 'The files are still on the disk.');
    }

    public function testFilesOnS3AreCopiedOnlyWhenAskedAndRestoredToTheBucket(): void
    {
        $endpoint = getenv('QUIRE_TEST_S3_ENDPOINT');

        if (!\is_string($endpoint) || '' === $endpoint) {
            self::markTestSkipped('No S3 service: set QUIRE_TEST_S3_ENDPOINT, as the Docker setup does.');
        }

        $bucket = 'quire-backup-'.bin2hex(random_bytes(4));
        $client = new S3Client(['endpoint' => $endpoint, 'region' => 'us-east-1', 'accessKeyId' => 'test', 'accessKeySecret' => 'test', 'pathStyleEndpoint' => 'true']);
        $client->createBucket(['Bucket' => $bucket])->resolve();
        $disks = new StorageDisks(
            ['site-photos' => ['adapter' => 's3', 'public' => true, 'bucket' => $bucket, 'endpoint' => $endpoint, 'access_key' => 'test', 'secret_key' => 'test', 'path_style' => true, 'owner' => 'site']],
            new DiskFactory(self::getContainer()->get('router'), new UriSigner('test')),
            new ServiceLocator([]),
        );

        try {
            $photo = new ObjectStore($disks, self::getContainer()->get(Connection::class))->store('site-photos', 'On S3 '.bin2hex(random_bytes(8)));
            self::getContainer()->get(Connection::class)->insert('quire.quire_fixture_hello_attachment', ['object_id' => $photo->id, 'name' => 'photo']);

            self::assertSame([0, 1], [$this->backups($disks)->create($this->directory.'/local.tar')->copied, $this->backups($disks)->create($this->directory.'/local2.tar')->notCopied], 'Buckets keep their own versions.');
            self::assertSame(1, $this->backups($disks)->create($this->directory.'/all.tar', BackupFiles::All)->copied);

            $disks->get('site-photos')->files->delete($photo->key);
            $restored = $this->backups($disks)->restore($this->directory.'/all.tar', static function (string $output): void {});

            self::assertSame([1, []], [$restored->restored, $restored->missing]);
            self::assertTrue($disks->get('site-photos')->files->fileExists($photo->key), 'Back in the bucket.');
        } finally {
            foreach ($client->listObjectsV2(['Bucket' => $bucket])->getContents() as $object) {
                $client->deleteObject(['Bucket' => $bucket, 'Key' => (string) $object->getKey()])->resolve();
            }

            $client->deleteBucket(['Bucket' => $bucket])->resolve();
        }
    }

    public function testADisabledExtensionsFilesAreBackedUpAndRestoredWhereTheSitePlacedThem(): void
    {
        $photo = $this->attach('quire-fixture-hello', 'photo');
        [$disks, $state, $placed] = $this->disabled('quire-fixture-hello', $photo);

        $report = $this->backups($disks, $state)->create($this->directory.'/site.tar');

        self::assertTrue($report->complete(), 'Disabling an extension keeps its files: they\'re not "missing".');
        self::assertSame([1, 1, []], [$report->objects, $report->copied, $report->missing]);
        self::assertArrayHasKey('files/quire-fixture-hello/'.$photo->key, new TarReader($this->directory.'/site.tar')->entries());

        // Into an empty schema, e.g. on a new server, with the extension still disabled.
        unlink($placed.'/'.$photo->key);
        self::getContainer()->get(Connection::class)->executeStatement('DROP SCHEMA quire CASCADE');
        $restored = $this->backups($disks, $state)->restore($this->directory.'/site.tar', static function (string $output): void {});

        self::assertSame([1, []], [$restored->restored, $restored->corrupt]);
        self::assertSame($this->contents($photo), file_get_contents($placed.'/'.$photo->key), 'Back on its disk, where the site placed it.');
    }

    public function testAnIncompleteBackupIsWrittenButDoesntCountAsOneThatWorked(): void
    {
        $lost = $this->attach('quire-fixture-hello', 'lost');
        $damaged = $this->attach('quire-fixture-hello', 'damaged');
        $this->backups()->create($this->directory.'/good.tar');
        $this->lose($lost);
        $this->disk('quire-fixture-hello')->files->write($damaged->key, strrev($this->contents($damaged)));

        $report = $this->backups()->create($this->directory.'/site.tar');

        self::assertFalse($report->complete());
        self::assertFileExists($this->directory.'/site.tar', 'The best copy there is.');
        $manifest = $this->manifest($this->directory.'/site.tar');
        self::assertFalse($manifest['complete']);
        self::assertSame([['file' => 'quire-fixture-hello:'.$lost->key, 'reason' => 'missing'], ['file' => 'quire-fixture-hello:'.$damaged->key, 'reason' => 'damaged']], $manifest['absent']);
        self::assertFalse($this->backups()->inspect($this->directory.'/site.tar')->complete);
        self::assertTrue($this->backups()->inspect($this->directory.'/good.tar')->complete);

        $log = self::getContainer()->get(BackupLog::class);
        self::assertFalse($log->last()?->ok);
        self::assertStringContainsString('Incomplete: 2 stored file(s) aren\'t in it', (string) $log->last()->problem);
        self::assertSame($this->directory.'/good.tar', $log->lastSuccessful()?->target, 'The last complete one is still the last that worked.');
    }

    public function testFilesOnADiskNothingHasMakeTheBackupIncompleteWhateverItCopies(): void
    {
        $this->attach('quire-fixture-hello', 'photo');
        $this->orphan('acme-gone');

        foreach ([BackupFiles::Local, BackupFiles::None] as $files) {
            $report = $this->backups()->create($this->directory.'/'.$files->value.'.tar', $files);

            self::assertSame([['acme-gone'], ['acme-gone:00/00/'.str_repeat('0', 64).'.jpg'], []], [$report->unknownDisks, $report->onUnknownDisks, $report->missing], 'Its own problem, not "missing".');
            self::assertFalse($report->complete(), 'Where its files are, and whether they\'d be copied, can\'t be known.');
            self::assertSame('unknown_disk', $this->manifest($this->directory.'/'.$files->value.'.tar')['absent'][0]['reason']);
        }
    }

    public function testQuireBackupExitsWith0OnlyForACompleteBackupAnd3ForAnIncompleteOne(): void
    {
        $this->attach('quire-fixture-hello', 'photo');
        $this->console('quire:backup', ['file' => $this->directory.'/complete.tar']);
        $this->console('quire:backup', ['file' => $this->directory.'/complete.tar'], expectedStatus: 1);
        $this->orphan('acme-gone');

        // 3, not BackupCommand::INCOMPLETE: Quire Server relies on the number (see ServerContract).
        $output = $this->console('quire:backup', ['file' => $this->directory.'/incomplete.tar'], expectedStatus: 3);
        self::assertStringContainsString('on the disk "acme-gone", which neither the site nor any installed extension has', $output);
        self::assertStringContainsString('Install the extension that declares it, or configure it in "quire.storage.disks"', $output);
        self::assertStringContainsString('The backup is incomplete: 1 stored file(s) aren\'t in it', $output);
        self::assertFileExists($this->directory.'/incomplete.tar');
        self::assertFalse(self::getContainer()->get(BackupLog::class)->last()?->ok);

        $output = $this->console('quire:backup', ['file' => $this->directory.'/accepted.tar', '--allow-incomplete' => true]);
        self::assertStringContainsString('acme-gone:00/00/', $output, 'The list is still shown.');
        self::assertStringContainsString('--allow-incomplete accepts that', $output);
        $last = self::getContainer()->get(BackupLog::class)->last();
        self::assertTrue($last?->ok);
        self::assertStringContainsString('Incomplete, which was allowed', (string) $last->problem);

        $output = $this->console('quire:restore', ['file' => $this->directory.'/accepted.tar', '--dry-run' => true], expectedStatus: 1);
        self::assertStringContainsString('The backup is incomplete: 1 stored file(s)', $output, 'Said before anything is restored.');
    }

    public function testAStreamedIncompleteBackupIsRecordedAsFailed(): void
    {
        $this->orphan('acme-gone');
        $bytes = '';

        $report = $this->backups()->stream(static function (string $data) use (&$bytes): void {
            $bytes .= $data;
        });

        self::assertFalse($report->complete());
        self::assertNotSame('', $bytes, 'The stream is complete, as a backup.');
        self::assertFalse(self::getContainer()->get(BackupLog::class)->last()?->ok);
    }

    public function testTheGarbageCollectorKeepsTheObjectsOfDisksNotInUse(): void
    {
        $photo = $this->objects()->store('quire-fixture-hello', 'Unused '.bin2hex(random_bytes(8)));
        $this->orphan('acme-gone');
        [$disks, , $placed] = $this->disabled('quire-fixture-hello', $photo);
        $connection = self::getContainer()->get(Connection::class);
        $own = new ObjectStore($disks, $connection)->store('site-files', 'Unused too '.bin2hex(random_bytes(8)));

        $collected = new GarbageCollector($connection, $disks)->collect(new \DateTimeImmutable('+1 day'));

        self::assertSame(1, $collected['objects'], 'Only the one on a disk in use.');
        self::assertFalse($disks->get('site-files')->files->fileExists($own->key));
        self::assertSame(['acme-gone', 'quire-fixture-hello'], $connection->fetchFirstColumn('SELECT disk FROM quire.storage_object ORDER BY disk'), 'The rows stay: the extension finds its files as it left them when it\'s back.');
        self::assertFileExists($placed.'/'.$photo->key);
    }

    public function testADiskPlacedThatNoInstalledExtensionDeclaresIsReported(): void
    {
        $container = self::getContainer();
        [$disks, $state] = $this->disabled('quire-fixture-hello');
        $typo = new StorageDisks([], new DiskFactory($container->get('router'), new UriSigner('test')), new ServiceLocator([]), $this->directory.'/root', [
            'quire-fixture-helo' => ['adapter' => 'local', 'public' => null, 'url' => null, 'path' => $this->directory.'/typo', 'service' => null],
            'quire-fixture-hello' => ['adapter' => 'local', 'public' => null, 'url' => null, 'path' => $this->directory.'/placed', 'service' => null],
        ]);
        $installed = new InstalledDisks($typo, $container->get(InstalledExtensions::class), $state, $container->get(OfflineBoot::class));

        self::assertSame(['quire-fixture-helo'], $installed->placedButUndeclared(), 'Not the disabled extension\'s: that one is declared.');
        self::assertSame([], new InstalledDisks($disks, $container->get(InstalledExtensions::class), $state, $container->get(OfflineBoot::class))->placedButUndeclared());

        $tester = new CommandTester(new Command('quire:storage:check')->setCode(new CheckDisksCommand($typo, $installed)));
        self::assertSame(Command::FAILURE, $tester->execute([]), 'It used to fail the build.');
        self::assertStringContainsString('"quire.storage.disks.quire-fixture-helo" places a disk that no installed extension declares', $tester->getDisplay());
    }

    public function testTheGarbageCollectorWaitsForRunningBackups(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        /** @var array{driver: 'pdo_pgsql', host: string, port: int, user: string, password: string, dbname: string} $params */
        $params = $connection->getParams();
        $backup = DriverManager::getConnection($params);
        $backup->executeStatement('SELECT pg_advisory_lock_shared(?)', [GarbageCollector::BACKUP_LOCK]);
        $connection->executeStatement("SET lock_timeout = '200ms'");

        try {
            self::getContainer()->get(GarbageCollector::class)->collect(new \DateTimeImmutable('-1 day'));
            self::fail('The garbage collector ran during a backup.');
        } catch (DriverException $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        } finally {
            $connection->executeStatement('RESET lock_timeout');
            $backup->close();
        }

        self::assertSame(['objects' => 0, 'files' => 0], self::getContainer()->get(GarbageCollector::class)->collect(new \DateTimeImmutable('-1 year')), 'And runs once it\'s over.');
    }

    private function backups(?StorageDisks $disks = null, ?ExtensionStateStoreInterface $state = null): Backups
    {
        $container = self::getContainer();
        $disks ??= $container->get(StorageDisks::class);
        $installed = new InstalledDisks($disks, $container->get(InstalledExtensions::class), $state ?? $container->get(ExtensionStateStoreInterface::class), $container->get(OfflineBoot::class));
        $checks = new RestoreChecks(
            $container->get(Connection::class),
            $container->get(InstalledExtensions::class),
            $state ?? $container->get(ExtensionStateStoreInterface::class),
            $installed,
            $container->get(OfflineBoot::class),
        );

        return new Backups($container->get(Connection::class), $container->get(PostgresTools::class), $checks, $container->get(AppRebuilderInterface::class), $container->get(ContentRenderer::CACHE_POOL), $installed, $container->get(BackupLog::class));
    }

    /**
     * The site as it is once the extension is disabled, having placed its disks in a directory of the
     * test's: they're dormant, not in use, and its files are there.
     *
     * @return array{StorageDisks, ExtensionStateStoreInterface, string} The disks, the extensions' state, where the files are
     */
    private function disabled(string $extension, StoredObject ...$objects): array
    {
        $container = self::getContainer();
        $placed = $this->directory.'/placed';

        foreach ($objects as $object) {
            new Filesystem()->dumpFile($placed.'/'.$object->key, $this->contents($object));
        }

        $container->get(Connection::class)->executeStatement('UPDATE quire.extension SET enabled = false WHERE id = ?', [$extension]);
        /** @var array<string, array<mixed>> $config */
        $config = $container->getParameter(StorageDisks::PARAMETER);
        $disks = new StorageDisks(
            [...array_filter($config, static fn (array $disk): bool => $extension !== $disk['owner']), 'site-files' => ['adapter' => 'local', 'public' => false, 'path' => $this->directory.'/site-files', 'owner' => 'site']],
            new DiskFactory($container->get('router'), new UriSigner('test')),
            new ServiceLocator([]),
            $this->directory.'/root',
            [$extension => ['adapter' => 'local', 'public' => null, 'url' => null, 'path' => $placed, 'service' => null]],
        );
        $enabled = array_values(array_diff($container->get(ExtensionStateStoreInterface::class)->enabledIds(), [$extension]));
        $state = new readonly class($enabled) implements ExtensionStateStoreInterface {
            /** @param list<string> $enabled */
            public function __construct(private array $enabled)
            {
            }

            public function enabledIds(): array
            {
                return $this->enabled;
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
        };

        return [$disks, $state, $placed];
    }

    /**
     * A stored file the database lists on a disk that neither the site nor any installed extension has.
     */
    private function orphan(string $disk): void
    {
        self::getContainer()->get(Connection::class)->insert('quire.storage_object', ['disk' => $disk, 'key' => '00/00/'.str_repeat('0', 64).'.jpg', 'size' => 3, 'sha256' => hash('sha256', 'abc'), 'mime_type' => 'image/jpeg']);
    }

    /**
     * @return array{complete: bool, absent: list<array{file: string, reason: string}>}
     */
    private function manifest(string $backup): array
    {
        $reader = new TarReader($backup);
        /** @var array{complete: bool, absent: list<array{file: string, reason: string}>} $manifest */
        $manifest = json_decode($reader->read($reader->entries()['manifest.json']), true, flags: \JSON_THROW_ON_ERROR);

        return $manifest;
    }

    /**
     * Stores a file with content of its own and attaches it, as an extension would.
     */
    private function attach(string $disk, string $name): StoredObject
    {
        $object = $this->objects()->store($disk, $name.' '.bin2hex(random_bytes(16)));
        self::getContainer()->get(Connection::class)->insert('quire.quire_fixture_hello_attachment', ['object_id' => $object->id, 'name' => $name]);

        return $object;
    }

    private function contents(StoredObject $object): string
    {
        return (string) stream_get_contents($this->objects()->read($object));
    }

    private function lose(StoredObject ...$objects): void
    {
        foreach ($objects as $object) {
            $this->disk($object->disk)->files->delete($object->key);
        }
    }

    private function objects(): ObjectStore
    {
        return self::getContainer()->get(ObjectStore::class);
    }

    private function disk(string $name): \Quire\Storage\Disk
    {
        return self::getContainer()->get(StorageDisks::class)->get($name);
    }
}
