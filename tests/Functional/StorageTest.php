<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Quire\Settings\Settings;
use Quire\Storage\Internal\GarbageCollector;
use Quire\Storage\Job\CollectGarbage;
use Quire\Storage\Job\CollectGarbageHandler;
use Quire\Storage\ObjectStore;
use Quire\Storage\StorageDisks;
use Quire\Storage\StoredObject;
use Quire\Testing\JobRunner;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Storage disks: files named after their content, served publicly or through signed URLs, and
 * collected once nothing uses them.
 */
final class StorageTest extends WebTestCase
{
    /** The smallest valid PNG: one transparent pixel */
    private const string PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\0\x01\0\0\x05\0\x01\x0d\x0a\x2d\xb4\0\0\0\0IEND\xaeB`\x82";

    private KernelBrowser $client;

    private Connection $connection;

    private ObjectStore $objects;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->objects = self::getContainer()->get(ObjectStore::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testFilesAreNamedAfterTheirContentAndStoredOnce(): void
    {
        $object = $this->objects->store('quire-fixture-hello', self::PNG, 'application/octet-stream');
        $sha256 = hash('sha256', self::PNG);

        self::assertSame(\sprintf('%s/%s/%s.png', substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256), $object->key);
        self::assertSame(['image/png', \strlen(self::PNG), $sha256], [$object->mimeType, $object->size, $object->sha256], 'The type comes from the content, not the caller.');
        self::assertSame(self::PNG, stream_get_contents($this->objects->read($object)));

        $stream = fopen('php://memory', 'w+');
        \assert(\is_resource($stream));
        fwrite($stream, self::PNG);
        rewind($stream);
        self::assertSame($object->id, $this->objects->store('quire-fixture-hello', $stream)->id, 'The same content is one object.');
        self::assertSame(1, $this->objectCount('quire-fixture-hello'));

        self::assertSame($object->id, $this->objects->find($object->id)?->id);
        self::assertNull($this->objects->find('not-an-id'));
    }

    public function testTheCallersTypeIsUsedOnlyWhenTheContentDoesntTell(): void
    {
        self::assertSame('text/csv', $this->objects->store('quire-fixture-hello', "name,score\nAda,3\n", 'text/csv; charset=utf-8')->mimeType);
        self::assertSame('text/plain', $this->objects->store('quire-fixture-hello', 'Just text, claiming to be a page.', 'text/html')->mimeType, 'Never a type a browser would run.');
        self::assertStringEndsWith('.txt', $this->objects->store('quire-fixture-hello', 'Plain words.')->key);
    }

    public function testPublicFilesAreServedForGood(): void
    {
        $object = $this->objects->store('quire-fixture-hello', self::PNG);
        $url = $this->objects->url($object);

        self::assertSame('/storage/quire-fixture-hello/'.$object->key, $url);
        self::assertMatchesRegularExpression('#^https?://[^/]+/storage/quire-fixture-hello/'.preg_quote($object->key, '#').'$#', $this->objects->url($object, absolute: true), 'With the site\'s address.');

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(self::PNG, $this->client->getInternalResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertResponseHeaderSame('Cache-Control', 'immutable, max-age=31536000, public');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'), 'Uploaded files never run scripts as the site.');

        $this->client->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => '"'.$object->sha256.'"']);
        self::assertResponseStatusCodeSame(304);

        $this->client->request('GET', '/storage/quire-fixture-hello/00/00/'.str_repeat('0', 64).'.png');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPrivateFilesNeedASignedURL(): void
    {
        $object = $this->objects->store('quire-fixture-hello-private', 'Members only.');

        $this->client->request('GET', '/storage/quire-fixture-hello-private/'.$object->key);
        self::assertResponseStatusCodeSame(404, 'Private disks have no public URLs.');

        try {
            $this->objects->url($object);
            self::fail('A private file got a public URL.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('is private', $e->getMessage());
        }

        $signed = $this->objects->temporaryUrl($object, new \DateTimeImmutable('+5 minutes'));
        self::assertMatchesRegularExpression('#^https?://[^/]+/storage/_signed/quire-fixture-hello-private/'.preg_quote($object->key, '#').'\?#', $signed);
        $this->client->request('GET', $signed);
        self::assertResponseIsSuccessful();
        self::assertSame('Members only.', $this->client->getInternalResponse()->getContent());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');

        $this->client->request('GET', str_replace('_hash=', '_hash=x', $signed));
        self::assertResponseStatusCodeSame(403, 'Tampered.');

        $this->client->request('GET', $this->objects->temporaryUrl($object, new \DateTimeImmutable('-1 second')));
        self::assertResponseStatusCodeSame(403, 'Expired.');
    }

    public function testTheDailyCleanUpKeepsUnusedFilesForTheGracePeriod(): void
    {
        $unused = $this->objects->store('quire-fixture-hello', 'Not used for two days.');
        $this->connection->executeStatement("UPDATE quire.storage_object SET stored_at = now() - interval '2 days' WHERE id = ?", [$unused->id]);
        $settings = self::getContainer()->get(Settings::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        $settings->set(CollectGarbageHandler::GRACE_SETTING, 3);
        $bus->dispatch(new CollectGarbage());
        JobRunner::run(self::getContainer());
        self::assertNotNull($this->objects->find($unused->id), 'Kept for 3 days.');

        $settings->set(CollectGarbageHandler::GRACE_SETTING, 1);
        $bus->dispatch(new CollectGarbage());
        JobRunner::run(self::getContainer());
        self::assertNull($this->objects->find($unused->id));
    }

    public function testUnusedObjectsAndUnrecordedFilesAreCollected(): void
    {
        $kept = $this->objects->store('quire-fixture-hello', 'Attached to something.');
        $unused = $this->objects->store('quire-fixture-hello', 'Not used by anything.');
        $recent = $this->objects->store('quire-fixture-hello', 'Stored a moment ago.');
        $this->connection->insert('quire.quire_fixture_hello_attachment', ['object_id' => $kept->id, 'name' => 'kept']);
        $this->connection->executeStatement("UPDATE quire.storage_object SET stored_at = now() - interval '2 days' WHERE id IN (?, ?)", [$kept->id, $unused->id]);

        // A file whose row was rolled back, written long ago, and one written a moment ago.
        $files = self::getContainer()->get(StorageDisks::class)->get('quire-fixture-hello')->files;
        $orphan = 'ee/ee/'.str_repeat('e', 64).'.txt';
        $files->write($orphan, 'Nobody recorded me.');
        touch($this->path('quire-fixture-hello', $orphan), time() - 2 * 86400);
        $fresh = 'ff/ff/'.str_repeat('f', 64).'.txt';
        $files->write($fresh, 'Upload in progress.');

        $collector = self::getContainer()->get(GarbageCollector::class);
        self::assertSame(['objects' => 1, 'files' => 1], $collector->collect(new \DateTimeImmutable('-1 day'), dryRun: true));
        self::assertTrue($files->fileExists($unused->key), 'A dry run deletes nothing.');

        self::assertSame(['objects' => 1, 'files' => 1], $collector->collect(new \DateTimeImmutable('-1 day')));
        self::assertFalse($files->fileExists($unused->key));
        self::assertNull($this->objects->find($unused->id));
        self::assertFalse($files->fileExists($orphan));
        self::assertTrue($files->fileExists($kept->key), 'Referenced by the fixture\'s table.');
        self::assertTrue($files->fileExists($recent->key), 'Within the grace period.');
        self::assertTrue($files->fileExists($fresh));

        // Storing the content again brings the object back, under the same key.
        self::assertSame($unused->key, $this->objects->store('quire-fixture-hello', 'Not used by anything.')->key);
        self::assertTrue($files->fileExists($unused->key));
        $files->delete($fresh);
    }

    public function testReferencedObjectsCantBeDeleted(): void
    {
        $object = $this->objects->store('quire-fixture-hello', 'In use.');
        $this->connection->insert('quire.quire_fixture_hello_attachment', ['object_id' => $object->id, 'name' => 'in use']);
        $this->connection->executeStatement('SAVEPOINT before_delete');

        try {
            $this->connection->executeStatement('DELETE FROM quire.storage_object WHERE id = ?', [$object->id]);
            self::fail('A referenced object was deleted.');
        } catch (DriverException $e) {
            self::assertSame('23001', $e->getSQLState(), 'ON DELETE RESTRICT refuses.');
            $this->connection->executeStatement('ROLLBACK TO SAVEPOINT before_delete');
        }

        self::assertInstanceOf(StoredObject::class, $this->objects->find($object->id));
    }

    public function testDisksAreListedAndChecked(): void
    {
        $list = $this->console('quire:storage:list');
        self::assertMatchesRegularExpression('/quire-fixture-hello\s+public\s+local: \S+var\/test-storage\/public\/quire-fixture-hello\s+quire-fixture-hello/', $list);
        self::assertMatchesRegularExpression('/quire-fixture-hello-private\s+private\s+local: \S+\/private\/quire-fixture-hello-private/', $list);

        self::assertStringContainsString('✔ quire-fixture-hello-private', $this->console('quire:storage:check'));
    }

    private function objectCount(string $disk): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM quire.storage_object WHERE disk = ?', [$disk]);

        return \is_int($count) ? $count : -1;
    }

    private function path(string $disk, string $key): string
    {
        return substr(self::getContainer()->get(StorageDisks::class)->get($disk)->location, \strlen('local: ')).'/'.$key;
    }

    private function console(string $command): string
    {
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel ?? throw new \LogicException())->find($command));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        return $tester->getDisplay();
    }
}
