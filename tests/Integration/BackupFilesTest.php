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
use Quire\Backup\Backups;
use Quire\Backup\Internal\PostgresTools;
use Quire\Backup\Internal\RestoreChecks;
use Quire\Backup\Internal\TarReader;
use Quire\Content\ContentRenderer;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Storage\Internal\DiskFactory;
use Quire\Storage\Internal\GarbageCollector;
use Quire\Storage\ObjectStore;
use Quire\Storage\StorageDisks;
use Quire\Storage\StoredObject;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

    private function backups(?StorageDisks $disks = null): Backups
    {
        $container = self::getContainer();
        $disks ??= $container->get(StorageDisks::class);
        $checks = new RestoreChecks(
            $container->get(Connection::class),
            $container->get(InstalledExtensions::class),
            $container->get(ExtensionStateStoreInterface::class),
            $disks,
            $container->getParameter('kernel.project_dir'),
            'test',
        );

        return new Backups($container->get(Connection::class), $container->get(PostgresTools::class), $checks, $container->get(AppRebuilderInterface::class), $container->get(ContentRenderer::CACHE_POOL), $disks);
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
