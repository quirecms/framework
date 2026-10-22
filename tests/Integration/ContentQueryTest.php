<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Field\FieldIndexes;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Exception\InvalidQueryException;
use Quire\Content\Status;
use Quire\Content\Type\Exception\UnknownContentTypeException;
use Quire\Testing\Database\QueryLog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * Content queries, with the scopes, visibility rule and relation of the fixture extension
 * (see tests/Fixtures/extensions/hello/extend.php).
 */
final class ContentQueryTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    private ContentRepository $content;

    public static function setUpBeforeClass(): void
    {
        // Indexes are built concurrently, which can't happen inside the test's transaction.
        self::getContainer()->get(FieldIndexes::class)->sync();
        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(ContentWriter::class);
        $this->content = self::getContainer()->get(ContentRepository::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testOnlyWhatVisitorsMaySeeIsReturned(): void
    {
        $published = $this->published('Published');
        $this->create('Draft');
        $scheduled = $this->create('Scheduled');
        $this->writer->transition($scheduled->id, $scheduled->version, Status::SCHEDULE, new \DateTimeImmutable('+1 day'));
        $due = $this->create('Due');
        $due = $this->writer->transition($due->id, $due->version, Status::SCHEDULE, new \DateTimeImmutable('+1 day'));
        // Due, but not published yet by quire:content:publish-due.
        $this->connection->executeStatement("UPDATE quire.content SET published_at = now() - interval '1 minute' WHERE id = ?", [$due->id]);
        $archived = $this->published('Archived');
        $this->writer->transition($archived->id, $archived->version, Status::ARCHIVE);
        $trashed = $this->published('Trashed');
        $this->writer->trash($trashed->id, $trashed->version);

        self::assertSame(['Due', 'Published'], $this->titles($this->content->query('post')->orderBy('title')));
        self::assertSame(['Archived', 'Draft', 'Due', 'Published', 'Scheduled'], $this->titles($this->content->query('post')->withoutVisibilityScope()->orderBy('title')));
        self::assertSame(['Archived', 'Draft', 'Due', 'Published', 'Scheduled', 'Trashed'], $this->titles($this->content->query('post')->withoutVisibilityScope()->withTrashed()->orderBy('title')));
        self::assertSame(['Trashed'], $this->titles($this->content->query('post')->withoutVisibilityScope()->onlyTrashed()));
        self::assertSame(['Due', 'Published'], $this->titles($this->content->query('post')->withoutVisibilityScope()->published()->orderBy('title')));
        self::assertSame([], $this->titles($this->content->query('post')->onlyTrashed()), 'Trashed items are never public.');

        foreach ($this->content->query('post')->withoutVisibilityScope()->withTrashed()->get() as $item) {
            self::assertSame(\in_array($item->title, ['Due', 'Published'], true), $item->isPublic(), \sprintf('The query and Content::isPublic() agree on "%s".', $item->title));
        }

        self::assertSame($published->id, $this->content->query('post')->where('slug', 'published')->first()?->id);
    }

    public function testDefaultOrderIsTheTypes(): void
    {
        $this->published('Old', at: '-3 days');
        $this->published('New', at: '-1 day');
        $this->published('Middle', at: '-2 days');

        self::assertSame(['New', 'Middle', 'Old'], $this->titles($this->content->query('post')), 'Posts sort by published_at, newest first.');
        self::assertSame(['Old', 'Middle', 'New'], $this->titles($this->content->query('post')->oldest()));

        $this->published('B page', type: 'page', position: 2, at: '-36 hours');
        $this->published('A page', type: 'page', position: 1, at: '-12 hours');
        self::assertSame(['A page', 'B page'], $this->titles($this->content->query('page')), 'Pages sort by position.');
        self::assertSame(['A page', 'New', 'B page', 'Middle', 'Old'], $this->titles($this->content->query('post', 'page')), 'Several types sort by published_at, newest first.');
    }

    public function testFiltersOnFields(): void
    {
        $this->published('Five', fields: ['rating' => 5, 'featured' => true, 'tags' => ['php', 'cms']]);
        $this->published('Three', fields: ['rating' => 3, 'tags' => ['php']]);
        $this->published('Unrated', fields: ['tags' => ['go']]);

        $query = $this->content->query('post')->orderBy('title');

        self::assertSame(['Five'], $this->titles($query->where('fields.rating', '>=', 4)));
        self::assertSame(['Five'], $this->titles($query->where('fields.rating', '>=', '4')), 'Numeric text, e.g. from a URL, is accepted.');
        self::assertSame(['Five', 'Three'], $this->titles($query->where('fields.rating', 'in', [3, 5])));
        self::assertSame(['Unrated'], $this->titles($query->where('fields.rating', null)));
        self::assertSame(['Five'], $this->titles($query->where('fields.featured', true)));
        self::assertSame(['Three', 'Unrated'], $this->titles($query->where('fields.featured', false)), 'The default (false) was stored.');
        self::assertSame(['Five', 'Three'], $this->titles($query->where('fields.tags', 'contains', 'php')));
        self::assertSame(['Five'], $this->titles($query->where('fields.tags', 'contains', ['php', 'cms'])));
        self::assertSame(['Five', 'Unrated'], $this->titles($query->where('fields.tags', 'contains any', ['cms', 'go'])));
        self::assertSame([], $this->titles($query->where('id', 'not-a-uuid')), 'A malformed ID matches nothing.');
        self::assertSame(['Five', 'Three', 'Unrated'], $this->titles($query->where('published_at', '<', new \DateTimeImmutable('+1 minute'))));
        self::assertSame(3, $query->count());
        self::assertSame(1, $query->where('fields.featured', true)->count());
    }

    public function testFiltersAndSortingUseTheFieldIndexes(): void
    {
        // Enough rows for the planner to prefer an index, and few that match.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO quire.content (type, status, locale, title, slug, body_format, published_at, fields)
            SELECT 'post', 'published', 'en', 'Post ' || g, 'post-' || g, 'markdown', now() - interval '1 day',
                   jsonb_build_object('rating', CASE WHEN g % 100 = 0 THEN 5 ELSE 1 END, 'tags', jsonb_build_array('tag' || (g % 200)),
                                      'event_at', to_char(timestamp '2026-01-01' + g * interval '1 hour', 'YYYY-MM-DD"T"HH24:MI:SS"+00:00"'))
            FROM generate_series(1, 5000) g
            SQL);
        $this->connection->executeStatement('ANALYZE quire.content');

        foreach ([
            $this->content->query('post')->where('fields.rating', '>=', 5),
            $this->content->query('post')->where('fields.tags', 'contains', 'tag7'),
            $this->content->query('post')->orderBy('fields.event_at')->limit(10),
        ] as $query) {
            [$sql, $params] = $query->toSql();
            /** @var list<string> $lines */
            $lines = $this->connection->fetchFirstColumn('EXPLAIN '.$sql, $params);
            $plan = implode("\n", $lines);

            self::assertStringContainsString(FieldIndexes::PREFIX, $plan, "The query uses a field index:\n".$sql."\n".$plan);
        }
    }

    public function testOnlyDeclaredFieldsCanBeUsed(): void
    {
        $query = $this->content->query('post');

        $this->assertInvalid('Post can\'t be filtered by "fields.mood": the field isn\'t declared with "sortable: true" or "filterable: true", so it has no index. Fields that can be filtered: rating, featured, tags, event_at.', static fn (): ContentQuery => $query->where('fields.mood', 'happy'));
        $this->assertInvalid('Post can\'t be sorted by "fields.nope": the field isn\'t declared. Fields that can be sorted: rating, featured, event_at.', static fn (): ContentQuery => $query->orderBy('fields.nope'));
        $this->assertInvalid('Content can\'t be filtered by "body".', static fn (): ContentQuery => $query->where('body', 'x'));
        $this->assertInvalid('Content can\'t be sorted by "body".', static fn (): ContentQuery => $query->orderBy('body'));
        $this->assertInvalid('"fields.rating" can\'t be compared with string: expected a number.', static fn (): ContentQuery => $query->where('fields.rating', 'five'));
        $this->assertInvalid('Post can\'t be sorted by "fields.tags": lists can\'t be sorted.', static fn (): ContentQuery => $query->orderBy('fields.tags'));
        $this->assertInvalid('"fields.tags" is a list: filter it with "contains"', static fn (): ContentQuery => $query->where('fields.tags', 'php'));
        $this->assertInvalid('Unknown operator "like"', static fn (): ContentQuery => $query->where('slug', 'like', 'a%'));
        $this->assertInvalid('The sort direction is "asc" or "desc", not "up".', static fn (): ContentQuery => $query->orderBy('title', 'up'));
        $this->assertInvalid('Greeting_card can\'t be filtered by "fields.featured": the field isn\'t declared.', fn (): ContentQuery => $this->content->query('post', 'greeting_card')->where('fields.featured', true));

        $this->expectException(UnknownContentTypeException::class);
        $this->content->query('nope');
    }

    public function testSortingPutsMissingValuesLastAndBreaksTies(): void
    {
        $this->published('Unrated');
        $this->published('Four', fields: ['rating' => 4]);
        $this->published('Two', fields: ['rating' => 2]);

        self::assertSame(['Four', 'Two', 'Unrated'], $this->titles($this->content->query('post')->orderBy('fields.rating', 'desc')));
        self::assertSame(['Two', 'Four', 'Unrated'], $this->titles($this->content->query('post')->orderBy('fields.rating')));
    }

    public function testPagination(): void
    {
        // The same date for all: the ID breaks the tie, so pages never overlap.
        foreach (range(1, 7) as $i) {
            $this->published('Post '.$i, at: '2026-01-01 12:00');
        }

        $query = $this->content->query('post');
        $pages = array_map(static fn (int $page): array => $query->paginate($page, 3)->items, [1, 2, 3]);
        $ids = array_map(static fn (Content $item): string => $item->id, array_merge(...$pages));

        self::assertCount(7, array_unique($ids));
        self::assertSame(array_map(static fn (Content $item): string => $item->id, $query->get()), $ids);

        $page = $query->paginate(3, 3);
        self::assertSame([1, 7, 3, 3], [\count($page), $page->total, $page->pageCount(), $page->page]);
        self::assertFalse($page->hasNextPage());
        self::assertTrue($page->hasPreviousPage());

        $beyond = $query->paginate(5, 3);
        self::assertSame([0, 7], [\count($beyond), $beyond->total], 'The total is still known past the last page.');
        self::assertTrue($beyond->isOutOfRange());
        self::assertSame(1, $query->paginate(-4, 3)->page, 'Page numbers below 1 mean the first page.');
    }

    public function testScopes(): void
    {
        $this->published('Featured', fields: ['featured' => true, 'tags' => ['php']]);
        $this->published('Plain', fields: ['tags' => ['go']]);

        self::assertSame(['Featured'], $this->titles($this->content->query('post')->scope('featured')));
        self::assertSame(['Plain'], $this->titles($this->content->query('post')->scope('tagged', 'go')));

        $this->assertInvalid('Unknown scope "nope". Registered: featured, tagged, popular.', fn (): ContentQuery => $this->content->query('post')->scope('nope'));
        $this->assertInvalid('The scope "featured" (from "quire-fixture-hello") doesn\'t apply to page; only to post.', fn (): ContentQuery => $this->content->query('page')->scope('featured'));
        $this->assertInvalid('Wrong arguments for the scope "tagged": Too few arguments', fn (): ContentQuery => $this->content->query('post')->scope('tagged'));
        $this->assertInvalid('Wrong arguments for the scope "tagged"', fn (): ContentQuery => $this->content->query('post')->scope('tagged', ['php']));
    }

    public function testSelectsFromAnExtensionsTable(): void
    {
        $one = $this->published('One like');
        $two = $this->published('Two likes');
        $this->published('No likes');
        $this->like($one, 'Ann');
        $this->like($two, 'Ann', 'Bob');

        $items = $this->content->query('post')->scope('popular')->get();

        self::assertSame(['Two likes', 'One like', 'No likes'], array_map(static fn (Content $item): string => $item->title, $items));
        self::assertSame([2, 1, 0], array_map(static fn (Content $item): mixed => $item->attribute('like_count'), $items));
        self::assertSame(['Two likes'], $this->titles($this->content->query('post')->scope('popular', 2)));

        $this->expectExceptionMessageIsOrContains('"like_count" isn\'t loaded for this post.');
        $this->content->query('post')->first()?->attribute('like_count');
    }

    public function testVisibilityRulesOfExtensionsApplyToTheirTypes(): void
    {
        $this->published('For Ann', type: 'greeting_card', fields: ['recipient' => 'Ann']);
        $this->published('For nobody', type: 'greeting_card', fields: ['recipient' => 'nobody']);
        $this->published('A post');

        self::assertSame(['For Ann'], $this->titles($this->content->query('greeting_card')));
        self::assertSame(['A post', 'For Ann'], $this->titles($this->content->query('greeting_card', 'post')->orderBy('title')), 'The rule leaves posts alone.');
        self::assertSame(['For Ann', 'For nobody'], $this->titles($this->content->query('greeting_card')->withoutVisibilityScope()->orderBy('title')));
        self::assertSame(['For Ann'], $this->titles($this->content->query('greeting_card')->withoutVisibilityScope()->published()), 'published() applies the rules of extensions too.');
    }

    public function testRelationsLoadInBatches(): void
    {
        $about = $this->published('About', type: 'page');
        $this->published('Team', type: 'page', parent: $about, position: 2);
        $this->published('History', type: 'page', parent: $about, position: 1);
        $this->create('Draft child', type: 'page', parent: $about);
        $contact = $this->published('Contact', type: 'page');

        $log = $this->queryLog();
        $log->start();
        $pages = $this->content->query('page')->with('children', 'parent')->get();
        self::assertCount(3, $log->stop(), 'One query for the pages and one per relation, however many pages.');

        $byTitle = array_column(array_map(static fn (Content $page): array => [$page->title, $page], $pages), 1, 0);
        self::assertSame(['History', 'Team'], array_map(static fn (Content $child): string => $child->title, $this->contentList($byTitle['About']->attribute('children'))), 'Visitors don\'t see the draft child.');
        self::assertSame([], $byTitle['Contact']->attribute('children'));
        self::assertNull($byTitle['About']->attribute('parent'));
        $parent = $byTitle['Team']->attribute('parent');
        self::assertInstanceOf(Content::class, $parent);
        self::assertSame($about->id, $parent->id);

        $draftChild = $this->content->query('page')->withoutVisibilityScope()->with('children')->where('id', $about->id)->first();
        self::assertCount(3, $this->contentList($draftChild?->attribute('children')), 'Editors see the draft child.');

        [$loaded] = $this->content->query('page')->with('children')->load([$contact]);
        self::assertSame([], $loaded->attribute('children'), 'Relations load into items fetched elsewhere.');

        $this->assertInvalid('The relation "likes" (from "quire-fixture-hello") doesn\'t apply to page; only to post.', fn (): ContentQuery => $this->content->query('page')->with('likes'));
        $this->assertInvalid('Unknown relation "nope".', fn (): ContentQuery => $this->content->query('page')->with('nope'));
    }

    public function testTranslationsAndExtensionRelations(): void
    {
        $english = $this->published('Hello');
        $german = $this->writer->create(new CreateContent('post', 'Hallo', locale: 'de', translationOf: $english->id));
        $german = $this->writer->transition($german->id, $german->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));
        $this->like($english, 'Ann', 'Bob');

        $posts = $this->content->query('post')->locale('en')->with('translations', 'likes')->get();

        self::assertCount(1, $posts);
        $translations = $posts[0]->attribute('translations');
        self::assertIsArray($translations);
        self::assertSame(['de'], array_keys($translations));
        self::assertInstanceOf(Content::class, $translations['de']);
        self::assertSame($german->id, $translations['de']->id);
        self::assertSame(['Ann', 'Bob'], $posts[0]->attribute('likes'));
        // @phpstan-ignore property.notFound (the magic property that templates use)
        self::assertSame(['Ann', 'Bob'], $posts[0]->likes, 'Attributes read as properties too, as in templates.');
    }

    public function testTemplateQueriesAreBoundedAndCantOptOutOfVisibility(): void
    {
        foreach (range(1, 12) as $i) {
            $this->published('Post '.$i, fields: ['featured' => 0 === $i % 2]);
        }

        $this->create('Draft');
        $this->like($this->content->query('post')->first() ?? self::fail(), 'Ann');

        self::assertSame('10', $this->render("{{ quire_content('post')|length }}"), 'Ten items by default.');
        self::assertSame('5', $this->render("{{ quire_content('post').latest.limit(5)|length }}"));
        self::assertSame('6', $this->render("{{ quire_content('post').scope('featured').limit(20)|length }}"));
        self::assertSame('12', $this->render("{{ quire_content('post').count }}"));
        self::assertSame('1', $this->render("{{ quire_content('post').scope('popular', 1)|length }}"), 'Scopes may use raw SQL, even from templates.');
        self::assertSame('Post 12', $this->render("{{ quire_content('post').where('fields.featured', true).first.title }}"));
        self::assertSame('2/2', $this->render("{% set p = quire_content('post').paginate(2, 10) %}{{ p|length }}/{{ p.pageCount }}"));

        foreach ([
            "quire_content('post').limit(101)" => 'Templates can show at most 100 items at once',
            "quire_content('post').paginate(1, 500)" => 'Pages have from 1 to 100 items.',
            "quire_content('post').withoutVisibilityScope" => 'withoutVisibilityScope() isn\'t available in templates.',
            "quire_content('post').withTrashed" => 'withTrashed() isn\'t available in templates.',
            "quire_content('post').whereSql('true')" => 'whereSql() isn\'t available in templates.',
        ] as $expression => $message) {
            try {
                $this->render(\sprintf('{{ %s|length }}', $expression));
                self::fail('Expected an error for '.$expression);
            } catch (RuntimeError $e) {
                self::assertStringContainsString($message, $e->getPrevious()?->getMessage() ?? $e->getMessage());
            }
        }
    }

    public function testQueriesAreImmutableAndLazy(): void
    {
        $this->published('One', fields: ['featured' => true]);
        $this->published('Two');

        $log = $this->queryLog();
        $log->start();
        $all = $this->content->query('post');
        $featured = $all->scope('featured');
        self::assertSame([], $log->stop(), 'Building a query doesn\'t run it.');

        self::assertCount(2, $all->get());
        self::assertCount(1, $featured->get());
        self::assertNotSame($all, $all->limit(1));
    }

    private function queryLog(): QueryLog
    {
        // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only)
        $log = self::getContainer()->get(QueryLog::class);
        \assert($log instanceof QueryLog);

        return $log;
    }

    /**
     * @return list<Content>
     */
    private function contentList(mixed $value): array
    {
        self::assertIsList($value);
        self::assertContainsOnlyInstancesOf(Content::class, $value);

        return $value;
    }

    private function render(string $template): string
    {
        $twig = self::getContainer()->get(Environment::class);

        return $twig->createTemplate($template)->render();
    }

    /**
     * @return list<string>
     */
    private function titles(ContentQuery $query): array
    {
        return array_map(static fn (Content $item): string => $item->title, $query->get());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function create(string $title, string $type = 'post', array $fields = [], ?Content $parent = null, ?int $position = null): Content
    {
        return $this->writer->create(new CreateContent($type, $title, fields: $fields, parentId: $parent?->id, position: $position));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function published(string $title, string $type = 'post', array $fields = [], ?Content $parent = null, ?int $position = null, string $at = '-1 hour'): Content
    {
        $item = $this->create($title, $type, $fields, $parent, $position);

        return $this->writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable($at));
    }

    private function like(Content $item, string ...$names): void
    {
        foreach ($names as $name) {
            $this->connection->insert('quire.quire_fixture_hello_like', ['content_id' => $item->id, 'name' => $name]);
        }
    }

    private function assertInvalid(string $message, \Closure $callback): void
    {
        try {
            $callback();
            self::fail('Expected an InvalidQueryException: '.$message);
        } catch (InvalidQueryException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
