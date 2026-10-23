<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use AsyncAws\S3\S3Client;
use Doctrine\DBAL\Connection;
use Quire\Storage\Internal\DiskFactory;
use Quire\Storage\Internal\GarbageCollector;
use Quire\Storage\ObjectStore;
use Quire\Storage\StorageDisks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * Storage disks on an S3-compatible service: the SeaweedFS service of the Docker setup (and of CI), at
 * QUIRE_TEST_S3_ENDPOINT. Skipped without it.
 */
final class S3StorageTest extends KernelTestCase
{
    private string $endpoint;

    private string $bucket;

    private S3Client $client;

    private Connection $connection;

    private StorageDisks $disks;

    private ObjectStore $objects;

    protected function setUp(): void
    {
        $endpoint = getenv('QUIRE_TEST_S3_ENDPOINT');

        if (!\is_string($endpoint) || '' === $endpoint) {
            self::markTestSkipped('No S3 service: set QUIRE_TEST_S3_ENDPOINT, as the Docker setup does.');
        }

        $this->endpoint = $endpoint;
        $this->bucket = 'quire-test-'.bin2hex(random_bytes(4));
        $this->client = new S3Client(['endpoint' => $endpoint, 'region' => 'us-east-1', 'accessKeyId' => 'test', 'accessKeySecret' => 'test', 'pathStyleEndpoint' => 'true']);
        $this->client->createBucket(['Bucket' => $this->bucket])->resolve();

        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $s3 = ['adapter' => 's3', 'bucket' => $this->bucket, 'region' => 'us-east-1', 'endpoint' => $endpoint, 'access_key' => 'test', 'secret_key' => 'test', 'path_style' => true, 'url' => null, 'label' => null, 'owner' => 'site'];
        $this->disks = new StorageDisks([
            'photos' => [...$s3, 'public' => true, 'prefix' => 'photos'],
            'downloads' => [...$s3, 'public' => false, 'prefix' => 'downloads'],
        ], new DiskFactory(self::getContainer()->get('router'), new UriSigner('test')), new ServiceLocator([]));
        $this->objects = new ObjectStore($this->disks, $this->connection);
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }

        $this->connection->rollBack();

        foreach ($this->client->listObjectsV2(['Bucket' => $this->bucket])->getContents() as $object) {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => (string) $object->getKey()])->resolve();
        }

        $this->client->deleteBucket(['Bucket' => $this->bucket])->resolve();
        parent::tearDown();
    }

    public function testFilesAreStoredOnceAndLinkedToTheBucket(): void
    {
        $object = $this->objects->store('photos', 'A photo, as far as this test cares.', 'image/jpeg');

        self::assertSame($object->id, $this->objects->store('photos', 'A photo, as far as this test cares.', 'image/jpeg')->id);
        self::assertTrue($this->client->objectExists(['Bucket' => $this->bucket, 'Key' => 'photos/'.$object->key])->isSuccess(), 'Under the disk\'s prefix.');

        $url = $this->objects->url($object);
        self::assertSame(rtrim($this->endpoint, '/').'/'.$this->bucket.'/photos/'.$object->key, $url);
        self::assertSame('A photo, as far as this test cares.', HttpClient::create()->request('GET', $url)->getContent(), 'The bucket allows anonymous reads here.');
    }

    public function testPrivateFilesGetPresignedURLs(): void
    {
        $object = $this->objects->store('downloads', 'Members only.');
        $url = $this->objects->temporaryUrl($object, new \DateTimeImmutable('+5 minutes'));

        self::assertStringContainsString('X-Amz-Signature=', $url, 'Signed by the S3 protocol, not by Quire.');
        self::assertSame('Members only.', HttpClient::create()->request('GET', $url)->getContent());
    }

    public function testGarbageIsCollectedFromTheBucket(): void
    {
        $unused = $this->objects->store('photos', 'Nothing uses me.');
        $this->disks->get('photos')->files->write('aa/aa/'.str_repeat('a', 64).'.txt', 'Nobody recorded me.');
        $this->disks->get('photos')->files->write('notes/readme.txt', 'Not an object: left alone.');

        // S3 dates files when they're written, so collect everything up to a minute from now.
        $collector = new GarbageCollector($this->connection, $this->disks);
        self::assertSame(['objects' => 1, 'files' => 1], $collector->collect(new \DateTimeImmutable('+1 minute')));

        self::assertFalse($this->disks->get('photos')->files->fileExists($unused->key));
        self::assertFalse($this->disks->get('photos')->files->fileExists('aa/aa/'.str_repeat('a', 64).'.txt'));
        self::assertTrue($this->disks->get('photos')->files->fileExists('notes/readme.txt'));
    }
}
