<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Testing\Database\QueryLog;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A listing page whose template queries content and an extension's relation (NFR-002: no N+1). The page is
 * the fixture extension's /_fixture/posts.
 */
final class ContentListingTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheQueryCountDoesNotGrowWithTheNumberOfItems(): void
    {
        $this->post('Liked twice', 'Ann', 'Bob');
        $onePost = $this->queriesFor('/_fixture/posts');

        self::assertSelectorTextSame('li.post', 'Liked twice: Ann, Bob');
        self::assertSelectorTextSame('.total', '1 posts, page 1 of 1');

        $this->post('Liked once', 'Cy');
        $this->post('Not liked');
        $this->post('Liked thrice', 'Ann', 'Bob', 'Cy');
        $fullPage = $this->queriesFor('/_fixture/posts');

        self::assertSame(['Liked thrice: Ann, Bob, Cy', 'Liked twice: Ann, Bob', 'Liked once: Cy'], $this->client->getCrawler()->filter('li.post')->each(static fn ($li): string => $li->text()));
        self::assertSelectorTextSame('.total', '4 posts, page 1 of 2');
        self::assertCount(2, $fullPage, "The whole request makes one query for the page and its total, and one for the likes:\n".implode("\n", $fullPage));
        self::assertCount(\count($onePost), $fullPage);

        $this->queriesFor('/_fixture/posts?page=2');
        self::assertSelectorTextSame('li.post', 'Not liked:');
    }

    /**
     * @return list<string> The database queries the page made
     */
    private function queriesFor(string $uri): array
    {
        // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only)
        $log = self::getContainer()->get(QueryLog::class);
        \assert($log instanceof QueryLog);
        $log->start();
        $this->client->request('GET', $uri);
        $queries = $log->stop();

        self::assertResponseIsSuccessful();

        return $queries;
    }

    private function post(string $title, string ...$likes): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', $title));
        $writer->transition($post->id, $post->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));

        foreach ($likes as $name) {
            $this->connection->insert('quire.quire_fixture_hello_like', ['content_id' => $post->id, 'name' => $name]);
        }
    }
}
