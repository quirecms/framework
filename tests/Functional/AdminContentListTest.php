<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Field\FieldIndexes;
use Quire\Testing\Database\QueryLog;
use Quire\User\CreateUser;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The admin's content lists as extensions see them: the fixture extension adds a column, a filter and an
 * action to the posts' list, sortable fields to posts, and a status with its transition to the workflow
 * (see tests/Fixtures/extensions/hello/extend.php).
 */
final class AdminContentListTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    private ContentWriter $writer;

    public static function setUpBeforeClass(): void
    {
        // Indexes are built concurrently, which can't happen inside the test's transaction.
        self::getContainer()->get(FieldIndexes::class)->sync();
        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(ContentWriter::class);

        self::getContainer()->get(UserWriter::class)->create(new CreateUser('Editor', 'editor@example.com', password: 'correct-horse-42', roles: ['editor']));
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'editor', '_password' => 'correct-horse-42']);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testExtensionsAddColumnsLoadedForThePageAtOnce(): void
    {
        $first = $this->post('First');
        $this->like($first, 'Ada', '<script>alert(1)</script>');

        $crawler = $this->client->request('GET', '/admin/content/post');
        $headings = $crawler->filter('thead th')->each(static fn (Crawler $th): string => $th->text());
        self::assertSame(['Title', 'Status', 'Author', 'Rating', 'Event at', 'Accent', 'Updated', 'Published', 'Liked by', 'Actions'], $headings, 'Sortable fields, then the dates, then the extension\'s column.');
        self::assertStringContainsString('Ada, &lt;script&gt;alert(1)&lt;/script&gt;', $crawler->filter('tbody tr')->first()->html(), 'A string from a column is escaped.');

        $pages = $this->client->request('GET', '/admin/content/page');
        self::assertNotContains('Liked by', $pages->filter('thead th')->each(static fn (Crawler $th): string => $th->text()), 'Only for the types it\'s added to.');

        $queries = static function (KernelBrowser $client): int {
            // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only)
            $log = self::getContainer()->get(QueryLog::class);
            \assert($log instanceof QueryLog);
            $log->start();
            $client->request('GET', '/admin/content/post');

            return \count($log->stop());
        };

        $few = $queries($this->client);

        foreach (range(1, 5) as $number) {
            $this->like($this->post('More '.$number), 'Grace');
        }

        self::assertSame($few, $queries($this->client), 'The same number of queries for 1 and 6 rows.');
    }

    public function testSortingBySortableFields(): void
    {
        $this->post('Three stars', ['rating' => 3]);
        $this->post('Five stars', ['rating' => 5]);
        $this->post('No stars');

        $crawler = $this->client->request('GET', '/admin/content/post?sort=-fields.rating');

        self::assertSame(['Five stars', 'Three stars', 'No stars'], $this->titles($crawler), 'Items without a value come last.');
        self::assertSame('5', $crawler->filter('tbody tr')->first()->filter('td')->eq(3)->text());
    }

    public function testExtensionsAddFilters(): void
    {
        $this->post('Featured post', ['featured' => true]);
        $this->post('Plain post');

        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertSame(['', 'yes', 'no'], $crawler->filter('select[name="filter[quire-fixture-hello.featured]"] option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));

        $form = $crawler->filter('form.list-tools')->form(['filter[quire-fixture-hello.featured]' => 'yes']);
        self::assertSame(['Featured post'], $this->titles($crawler = $this->client->submit($form)));
        self::assertSame('yes', $crawler->filter('select[name="filter[quire-fixture-hello.featured]"] option[selected]')->attr('value'), 'The choice is kept.');
        self::assertSame(['Plain post'], $this->titles($this->client->request('GET', '/admin/content/post?filter[quire-fixture-hello.featured]=no')));
        self::assertCount(2, $this->titles($this->client->request('GET', '/admin/content/post?filter[quire-fixture-hello.featured]=maybe')), 'An unknown value doesn\'t filter.');
    }

    public function testExtensionsAddActionsThatReportWhyTheyFail(): void
    {
        $plain = $this->post('Plain post');
        $refusing = $this->post('Unfeaturable');
        $featured = $this->post('Featured post', ['featured' => true]);

        $crawler = $this->client->request('GET', '/admin/content/post?sort=title');
        self::assertContains('extension:quire-fixture-hello.feature', $crawler->filter('select[name=action] option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertCount(0, $crawler->filter(\sprintf('button[value="extension:quire-fixture-hello.feature|%s"]', $featured->id)), 'Not where it isn\'t available.');

        $token = (string) $crawler->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/content/post', ['_token' => $token, 'action' => 'extension:quire-fixture-hello.feature', 'ids' => [$plain->id, $refusing->id]]);
        $this->client->followRedirect();

        self::assertTrue(self::getContainer()->get(ContentRepository::class)->get($plain->id)->fields['featured'] ?? null);
        self::assertSelectorTextSame('.notice--success', 'Feature: done for 1 item.');
        self::assertSelectorTextSame('.notice--error', 'Feature wasn\'t possible for “Unfeaturable”: This post can\'t be featured.');
    }

    public function testStatusesAndTransitionsOfExtensions(): void
    {
        $draft = $this->post('Draft');

        $crawler = $this->client->request('GET', '/admin/content/post');
        $actions = $crawler->filter(\sprintf('button[value$="|%s"]', $draft->id))->each(static fn (Crawler $b): string => explode('|', (string) $b->attr('value'))[0]);
        self::assertSame(['transition:publish', 'transition:submit', 'extension:quire-fixture-hello.feature', 'trash'], $actions);

        $this->client->submit($crawler->filter(\sprintf('button[value="transition:submit|%s"]', $draft->id))->form());
        $crawler = $this->client->followRedirect();

        self::assertContains('In review 1', $crawler->filter('.views a')->each(static fn (Crawler $a): string => $a->text()), 'A status without a translation is named after its ID.');
        self::assertSame(['Draft'], $this->titles($this->client->request('GET', '/admin/content/post?view=in_review')));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(string $title, array $fields = []): Content
    {
        return $this->writer->create(new CreateContent('post', $title, fields: $fields));
    }

    private function like(Content $item, string ...$names): void
    {
        foreach ($names as $name) {
            $this->connection->insert('quire.quire_fixture_hello_like', ['content_id' => $item->id, 'name' => $name]);
        }
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('.content-list__title')->each(static fn (Crawler $title): string => $title->text());
    }
}
