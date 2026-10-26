<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Query\Exception\InvalidQueryException;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Status;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Counting content by its properties (facets), and queries written down to be made again.
 */
final class ContentFacetsTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    private ContentRepository $content;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        // Only this test's items.
        $this->connection->executeStatement('DELETE FROM quire.content');
        $this->writer = self::getContainer()->get(ContentWriter::class);
        $this->content = self::getContainer()->get(ContentRepository::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testCountsByColumnsAndFields(): void
    {
        $this->post('One', tags: ['php', 'cms'], publish: true);
        $this->post('Two', tags: ['php'], publish: true);
        $this->post('Drei', tags: ['php', 'symfony'], locale: 'de');
        $this->post('Four');
        $this->writer->create(new CreateContent('page', 'About'));
        $posts = $this->content->query('post')->withoutVisibilityScope();

        self::assertSame(['draft' => 2, 'published' => 2], $posts->countBy('status'));
        self::assertSame(['en' => 3, 'de' => 1], $posts->countBy('locale'));
        self::assertSame(['php' => 3, 'cms' => 1, 'symfony' => 1], $posts->countBy('fields.tags'), 'Each value of a list; most items first.');
        self::assertSame(['false' => 4], $posts->countBy('fields.featured'), 'A field\'s default counts.');
        self::assertSame(['post' => 4, 'page' => 1], $this->content->query('post', 'page')->withoutVisibilityScope()->countBy('type'));
        self::assertSame(['php' => 1, 'symfony' => 1], $posts->where('locale', 'de')->countBy('fields.tags'), 'Within what the query finds.');
        self::assertSame(['php' => 3], $posts->countBy('fields.tags', limit: 1));
        self::assertSame(['published' => 2], $this->content->query('post')->countBy('status'), 'What visitors may see, unless the query opts out.');
    }

    public function testAFacetIsCountedWithoutItsOwnFilter(): void
    {
        $this->post('One', publish: true);
        $this->post('Two');
        $drafts = $this->content->query('post')->withoutVisibilityScope()->status(Status::DRAFT)->search('two');

        self::assertSame(['draft' => 1], $drafts->countBy('status'));
        // The other statuses to choose from, with the search still applied.
        self::assertSame(['draft' => 1], $drafts->withoutWhere('status')->countBy('status'));
        self::assertSame(['draft' => 1, 'published' => 1], $this->content->query('post')->withoutVisibilityScope()->status(Status::DRAFT)->withoutWhere('status')->countBy('status'));
        self::assertSame(1, $this->content->query('post')->withoutVisibilityScope()->status(Status::DRAFT)->withoutWhere('status')->where('status', Status::PUBLISHED)->count());
    }

    public function testDatesAreCountedPerPeriodInTheSitesTimeZone(): void
    {
        self::getContainer()->get(Settings::class)->set('site.timezone', 'Europe/Berlin');
        // 23:30 in UTC is already October in Berlin.
        $late = $this->post('Late', publish: true);
        $this->connection->executeStatement("UPDATE quire.content SET published_at = '2026-09-30 23:30:00+00' WHERE id = ?", [$late->id]);
        $early = $this->post('Early', publish: true);
        $this->connection->executeStatement("UPDATE quire.content SET published_at = '2026-09-02 08:00:00+00' WHERE id = ?", [$early->id]);
        $posts = $this->content->query('post');

        self::assertSame(['2026-10' => 1, '2026-09' => 1], $posts->countBy('published_at', per: 'month'), 'Latest first.');
        self::assertSame(['2026' => 2], $posts->countBy('published_at', per: 'year'));
        self::assertSame(['2026-10-01' => 1, '2026-09-02' => 1], $posts->countBy('published_at', per: 'day'));
        self::assertSame(['2026-W40' => 1, '2026-W36' => 1], $posts->countBy('published_at', per: 'week'));
    }

    public function testTemplatesCount(): void
    {
        $this->post('One', publish: true);
        $this->post('Two');
        $twig = self::getContainer()->get('twig');
        $year = new \DateTimeImmutable()->format('Y');

        self::assertSame($year.': 1;', $twig->createTemplate("{% for period, count in quire_content('post').countBy('published_at', per: 'year') %}{{ period }}: {{ count }};{% endfor %}")->render());
    }

    public function testRelationsAreCountedByTheLinkedItem(): void
    {
        [$first, $second] = [$this->post('First'), $this->post('Second')];
        $this->writer->create(new CreateContent('greeting_card', 'Ada', fields: ['recipient' => 'Ada', 'posts' => [$first->id, $second->id]]));
        $this->writer->create(new CreateContent('greeting_card', 'Grace', fields: ['recipient' => 'Grace', 'posts' => [$first->id]]));

        self::assertSame([$first->id => 2, $second->id => 1], $this->content->query('greeting_card')->withoutVisibilityScope()->countBy('fields.posts'));
    }

    public function testWhatCantBeCounted(): void
    {
        $posts = $this->content->query('post');

        foreach ([
            'Content can\'t be counted by "title".' => static fn (): array => $posts->countBy('title'),
            'Count "published_at" per year, month, week or day.' => static fn (): array => $posts->countBy('published_at'),
            // @phpstan-ignore argument.type (testing the runtime check)
            'Count dates per year, month, week or day, not per "hour".' => static fn (): array => $posts->countBy('published_at', per: 'hour'),
            'Only dates (published_at, created_at, updated_at) are counted per period.' => static fn (): array => $posts->countBy('status', per: 'month'),
            'Post can\'t be filtered by "fields.mood"' => static fn (): array => $posts->countBy('fields.mood'),
        ] as $message => $count) {
            try {
                $count();
                self::fail('Counted: '.$message);
            } catch (InvalidQueryException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testAQueryCanBeWrittenDownAndMadeAgain(): void
    {
        $this->post('Markdown tips', tags: ['php'], publish: true);
        $this->post('Markdown drafts', tags: ['php']);
        $query = $this->content->query('post')
            ->withoutVisibilityScope()
            ->scope('tagged', 'php')
            ->where('created_at', '<', new \DateTimeImmutable('+1 day'))
            ->search('markdown')
            ->select('word_count', 'SELECT array_length(regexp_split_to_array(c.body, \'\s+\'), 1)')
            ->orderBy('title')
            ->limit(5);

        $spec = json_decode(json_encode($query->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        /** @var array{types: non-empty-list<string>, template?: bool, calls?: list<array{0: string, 1: list<mixed>}>} $spec */
        $again = $this->content->queryFromArray($spec);

        self::assertSame($query->toSql(), $again->toSql(), 'Through JSON, e.g. in a job.');
        self::assertSame(['Markdown drafts', 'Markdown tips'], array_map(static fn (Content $item): string => $item->title, $again->get()));
        self::assertSame(['scope', ['tagged', 'php']], $spec['calls'][1] ?? null, 'A scope is written down, not what it does.');

        self::assertSame(
            $query->withoutWhere('created_at')->toArray()['calls'],
            array_values(array_filter($query->toArray()['calls'], static fn (array $call): bool => 'where' !== $call[0] || 'created_at' !== $call[1][0])),
        );
    }

    public function testOnlyQueriesCanBeMadeFromWhatsWrittenDown(): void
    {
        try {
            $this->content->query('post')->select('answer', 'SELECT ?', [new \stdClass()])->toArray();
            self::fail('An object was written down.');
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString('it was made with stdClass, which isn\'t a value JSON can hold', $e->getMessage());
        }

        $this->expectExceptionMessageIsOrContains('A query can\'t be made with "delete()".');
        $this->content->queryFromArray(['types' => ['post'], 'calls' => [['delete', []]]]);
    }

    public function testScheduledChangesInATimeSpan(): void
    {
        $post = $this->post('Opening hours', publish: true);
        $pendingWriter = self::getContainer()->get(PendingRevisionWriter::class);
        $soon = $pendingWriter->create($post->id);
        $pendingWriter->update(new UpdatePendingRevision($soon->id, 1)->title('Opening hours, new'));
        $soon = $pendingWriter->schedule($soon->id, 2, new \DateTimeImmutable('2030-01-02 09:00 UTC'));
        $later = $pendingWriter->create($post->id);
        $pendingWriter->schedule($later->id, 1, new \DateTimeImmutable('2030-02-01 09:00 UTC'));

        $week = self::getContainer()->get(PendingRevisionRepository::class)->scheduledBetween(new \DateTimeImmutable('2030-01-01 00:00 UTC'), new \DateTimeImmutable('2030-01-08 00:00 UTC'));

        self::assertSame([$soon->id], array_map(static fn ($pending): string => $pending->id, $week));
    }

    /**
     * @param list<string> $tags
     */
    private function post(string $title, array $tags = [], bool $publish = false, string $locale = 'en'): Content
    {
        $post = $this->writer->create(new CreateContent('post', $title, body: 'Text of '.$title.'.', locale: $locale, fields: [] === $tags ? [] : ['tags' => $tags]));

        return $publish ? $this->writer->transition($post->id, $post->version, Status::PUBLISH) : $post;
    }
}
