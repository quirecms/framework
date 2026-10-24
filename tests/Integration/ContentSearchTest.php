<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Query\ContentQuery;
use Quire\Content\Query\Internal\SearchSql;
use Quire\Content\Status;
use Quire\Testing\Database\QueryLog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Markup;

/**
 * Full-text search in content queries (ContentQuery::search()).
 */
final class ContentSearchTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    private ContentRepository $content;

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

    public function testFindsItemsByTheStemsOfTheirLanguage(): void
    {
        $this->published('Running a site', body: 'How it runs.');
        $this->published('Katzen', body: 'Über Katzen und Hunde.', locale: 'de');
        $this->published('Gardening');

        self::assertSame(['Running a site'], $this->titles($this->content->query('post')->search('run')), 'English stems: "run" finds "running" and "runs".');
        self::assertSame(['Katzen'], $this->titles($this->content->query('post')->search('Katze')), 'German stems for a German item.');
        self::assertSame(['Katzen'], $this->titles($this->content->query('post')->search('hund')));
        self::assertSame([], $this->titles($this->content->query('post')->search('runner up')), 'All words must match.');
    }

    public function testTitlesWeighMoreThanExcerptsAndBodies(): void
    {
        $this->published('About gardens', body: 'Nothing on markdown.');
        $this->published('Markdown in practice');
        $this->published('Notes', excerpt: 'Why Markdown?');

        $results = $this->content->query('post')->search('markdown')->get();

        self::assertSame(['Markdown in practice', 'Notes', 'About gardens'], array_map(static fn (Content $item): string => $item->title, $results));
        $ranks = array_map(static fn (Content $item): mixed => $item->attribute('search_rank'), $results);
        self::assertContainsOnlyFloat($ranks);
        self::assertGreaterThan($ranks[1], $ranks[0]);
        self::assertGreaterThan($ranks[2], $ranks[1]);
        self::assertLessThanOrEqual(1.0, $ranks[0]);
    }

    public function testAnOrderOfTheQueryReplacesRelevance(): void
    {
        $this->published('Markdown basics', at: '-2 hours');
        $this->published('Writing', body: 'With markdown.', at: '-1 hour');

        self::assertSame(['Markdown basics', 'Writing'], $this->titles($this->content->query('post')->search('markdown')));
        self::assertSame(['Writing', 'Markdown basics'], $this->titles($this->content->query('post')->search('markdown')->latest()));
        self::assertSame(['Writing', 'Markdown basics'], $this->titles($this->content->query('post')->search('markdown')->orderBy('search_rank')));
    }

    public function testSearchSyntax(): void
    {
        $this->published('Site search', body: 'Search the whole site.');
        $this->published('Search plugin', body: 'A plugin for the site.');

        self::assertSame(['Site search'], $this->titles($this->content->query('post')->search('"site search"')), 'A phrase.');
        self::assertSame(['Site search'], $this->titles($this->content->query('post')->search('site -plugin')), 'Without a word.');
        self::assertCount(2, $this->content->query('post')->search('whole or plugin')->get());
        self::assertCount(2, $this->content->query('post')->search('site & | ! :* (')->get(), 'Characters that tsquery reads as operators are only text.');
    }

    public function testThePrefixOfTheLastWordMatchesAsYouType(): void
    {
        $this->published('Markdown editor');
        $this->published('Marketing');

        self::assertSame(['Markdown editor', 'Marketing'], $this->titles($this->content->query('post')->search('mar', prefix: true)->orderBy('title')));
        self::assertSame(['Markdown editor'], $this->titles($this->content->query('post')->search('editor mark', prefix: true)));
        self::assertSame(['Markdown editor'], $this->titles($this->content->query('post')->search("editor' & !mark", prefix: true)), 'Only the words count.');
        self::assertSame([], $this->titles($this->content->query('post')->search('mar', prefix: false)));
    }

    public function testVisitorsOnlyFindWhatTheyMaySee(): void
    {
        $this->published('Public markdown');
        $this->create('Draft markdown');
        $trashed = $this->published('Trashed markdown');
        $this->writer->trash($trashed->id, $trashed->version);

        self::assertSame(['Public markdown'], $this->titles($this->content->query('post')->search('markdown')));
        self::assertSame(['Draft markdown', 'Public markdown'], $this->titles($this->content->query('post')->withoutVisibilityScope()->search('markdown')->orderBy('title')));
        self::assertSame(['Trashed markdown'], $this->titles($this->content->query('post')->withoutVisibilityScope()->onlyTrashed()->search('markdown')));
        self::assertSame(1, $this->content->query('post')->search('markdown')->count());
    }

    public function testSnippetsShowTheTermsWithoutMarkdownAndAreSafeHtml(): void
    {
        $this->published('Guide', body: "# Heading\n\nUse **Markdown** with [a link](https://example.com) and <script>alert(1)</script> text.\n\nMuch later, markdown again.");
        $this->published('Other', body: 'No match here.', excerpt: 'Markdown in the excerpt.');

        $log = $this->queryLog();
        $log->start();
        $page = $this->content->query('post')->search('markdown', snippets: true)->paginate(1, 10);
        self::assertCount(2, $log->stop(), 'One query for the page, one for its snippets.');

        $snippets = [];

        foreach ($page->items as $item) {
            $snippet = $item->attribute('search_snippet');
            self::assertInstanceOf(Markup::class, $snippet);
            $snippets[$item->title] = (string) $snippet;
        }

        self::assertStringContainsString('Use <mark>Markdown</mark> with a link and', $snippets['Guide']);
        self::assertStringContainsString('alert(1)', $snippets['Guide']);
        self::assertStringNotContainsString('<script', $snippets['Guide'], 'HTML in the text is never markup in the snippet.');
        self::assertStringNotContainsString('**', $snippets['Guide']);
        self::assertStringNotContainsString('https://example.com', $snippets['Guide']);
        self::assertStringContainsString('<mark>Markdown</mark> in the excerpt', $snippets['Other']);
        self::assertArrayNotHasKey('search_snippet', $this->content->query('post')->search('markdown')->first()->attributes ?? [], 'Only when asked for.');
    }

    public function testBlankTermsChangeNothing(): void
    {
        $this->published('One');
        $this->published('Two');

        self::assertCount(2, $this->content->query('post')->search('  ')->get());
        self::assertCount(2, $this->content->query('post')->search('!? ', prefix: true)->get());
    }

    public function testTemplatesCanSearch(): void
    {
        $this->published('Markdown basics');
        $this->published('Gardening');

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate("{% for post in quire_content('post').search('markdown', snippets: true) %}{{ post.title }}{% endfor %}")->render();

        self::assertSame('Markdown basics', $html);
    }

    public function testTheSearchUsesTheIndex(): void
    {
        $parsed = SearchSql::parse('markdown', false);
        self::assertNotNull($parsed);
        ['sql' => $sql, 'params' => $params] = SearchSql::condition($parsed);
        // Few rows in the test database: make the planner prove that the condition can use the index.
        $this->connection->executeStatement('SET LOCAL enable_seqscan = off');

        $plan = implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $this->connection->fetchFirstColumn('EXPLAIN SELECT c.id FROM quire.content c WHERE '.$sql, $params)));

        self::assertStringContainsString('content_search_idx', $plan);
    }

    /**
     * @return list<string>
     */
    private function titles(ContentQuery $query): array
    {
        return array_map(static fn (Content $item): string => $item->title, $query->get());
    }

    private function create(string $title, string $body = '', ?string $excerpt = null, ?string $locale = null): Content
    {
        return $this->writer->create(new CreateContent('post', $title, body: $body, locale: $locale, excerpt: $excerpt));
    }

    private function published(string $title, string $body = '', ?string $excerpt = null, ?string $locale = null, string $at = '-1 hour'): Content
    {
        $item = $this->create($title, $body, $excerpt, $locale);

        return $this->writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable($at));
    }

    private function queryLog(): QueryLog
    {
        // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only)
        $log = self::getContainer()->get(QueryLog::class);
        \assert($log instanceof QueryLog);

        return $log;
    }
}
