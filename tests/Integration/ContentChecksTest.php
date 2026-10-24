<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Check\CheckResult;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Check\Level;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Status;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Content checks (CNT-066): core's, and the fixture extension's "quire-fixture-hello.shouting" (titles in
 * capitals, for posts only), at the levels the site sets.
 */
final class ContentChecksTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    private ContentChecker $checker;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(ContentWriter::class);
        $this->checker = self::getContainer()->get(ContentChecker::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testImagesNeedADescription(): void
    {
        $post = $this->draft('Pictures', "Intro.\n\n![](/img/chart.png) and ![A map](/img/map.png)\n\n    ![](/img/in-code.png)\n\n`![](/img/inline-code.png)`\n");

        self::assertSame([['core.alt-text', 'body', 3, 'The image “chart.png” has no description. Describe it in the brackets: ![what it shows](/img/chart.png).']], $this->found($post, 'core.alt-text'), 'Images in code are only code.');
    }

    public function testLinksToTheSiteLeadWhereVisitorsCanGo(): void
    {
        $this->published('About', type: 'page');
        $draft = $this->draft('Draft page', type: 'page');
        $post = $this->draft('Links', "[about](/about) [draft](/draft-page) [gone](/gone) [again](/gone#top)\n\n[sign in](/login) [site](http://localhost/about) [other site](https://example.com/gone) [mail](mailto:a@example.com) [anchor](#top) [relative](other-post)\n");

        self::assertSame([
            ['core.links', 'body', 1, 'The link to “/draft-page” leads to no page: the item may be a draft, in the trash, or gone.'],
            ['core.links', 'body', 1, 'The link to “/gone” leads to no page: the item may be a draft, in the trash, or gone.'],
        ], $this->found($post, 'core.links'), 'Each path once; pages that aren\'t content, other sites and anchors are fine.');
        self::assertSame(Status::DRAFT, $draft->status);
    }

    public function testExcerptsAndTitles(): void
    {
        $post = $this->draft(str_repeat('Long title ', 7));
        $page = $this->draft('A page', type: 'page');

        self::assertSame([['core.excerpt', 'excerpt', null, 'There\'s no excerpt: lists and search engines then show the start of the text, which may not summarise it.']], $this->found($post, 'core.excerpt'));
        self::assertSame([], $this->found($page, 'core.excerpt'), 'Pages have no excerpt.');
        self::assertSame([['core.title-length', 'title', null, 'The title has 76 characters; search results show about 60. Put what matters first.']], $this->found($post, 'core.title-length'));
    }

    public function testExtensionsAddChecksForTheirTypes(): void
    {
        self::assertSame([['quire-fixture-hello.shouting', 'title', null, 'The title is in capitals, which reads as shouting.']], $this->found($this->draft('HELLO WORLD'), 'quire-fixture-hello.shouting'));
        self::assertSame([], $this->found($this->draft('HELLO WORLD', type: 'page'), 'quire-fixture-hello.shouting'));

        $ids = array_column($this->checker->checks('page'), 'id');
        self::assertNotContains('quire-fixture-hello.shouting', $ids);
        self::assertContains('core.alt-text', $ids);
    }

    public function testTheSiteSetsTheLevels(): void
    {
        $post = $this->draft('Pictures', '![](/a.png)');
        self::assertSame(Level::Warning, $this->results($post, 'core.alt-text')[0]->level);

        $this->levels(['core.alt-text' => 'error', 'core.excerpt' => 'off']);
        self::assertSame(Level::Error, $this->results($post, 'core.alt-text')[0]->level);
        self::assertSame([], $this->results($post, 'core.excerpt'));
        self::assertTrue($this->checker->check($post)->hasErrors());
        self::assertSame(Level::Error, $this->checker->check($post)->results[0]->level, 'Errors first.');
    }

    public function testErrorsBlockEveryWayOfPublishing(): void
    {
        $this->levels(['core.alt-text' => 'error']);
        $post = $this->draft('Pictures', '![](/a.png)');

        $this->assertBlocked(fn (): Content => $this->writer->transition($post->id, $post->version, Status::PUBLISH));
        $this->assertBlocked(fn (): Content => $this->writer->transition($post->id, $post->version, Status::SCHEDULE, new \DateTimeImmutable('+1 day')));
        self::assertSame(Status::DRAFT, $this->content($post)->status, 'Nothing changed.');

        // Drafts may have errors: they're work in progress.
        $post = $this->writer->update(new UpdateContent($post->id, $post->version)->body('![](/b.png)'));

        $post = $this->writer->update(new UpdateContent($post->id, $post->version)->body('![A](/b.png)'));
        $post = $this->writer->transition($post->id, $post->version, Status::PUBLISH);

        // Changes to what visitors see, directly or as pending revisions.
        $this->assertBlocked(fn (): Content => $this->writer->update(new UpdateContent($post->id, $post->version)->body('![](/c.png)')));
        $pendingWriter = self::getContainer()->get(PendingRevisionWriter::class);
        $pending = $pendingWriter->create($post->id);
        $pending = $pendingWriter->update(new UpdatePendingRevision($pending->id, $pending->version)->body('![](/c.png)'));
        $this->assertBlocked(fn (): Content => $this->writer->publishPendingRevision($pending->id, $pending->version));
        self::assertSame('![A](/b.png)', $this->content($post)->body);
    }

    public function testScheduledItemsGoLiveWithoutBeingCheckedAgain(): void
    {
        $post = $this->draft('Pictures', '![](/a.png)');
        $post = $this->writer->transition($post->id, $post->version, Status::SCHEDULE, new \DateTimeImmutable('+1 day'));
        $this->connection->executeStatement("UPDATE quire.content SET published_at = now() - interval '1 minute' WHERE id = ?", [$post->id]);
        $this->levels(['core.alt-text' => 'error']);

        $this->writer->publishDue();

        self::assertSame(Status::PUBLISHED, $this->content($post)->status, 'Visitors see it already: it passed the checks when it was scheduled.');
    }

    /**
     * @return list<array{string, ?string, ?int, string}>
     */
    private function found(Content $item, string $check): array
    {
        return array_map(static fn (CheckResult $result): array => [$result->check, $result->finding->property, $result->finding->line, $result->finding->message], $this->results($item, $check));
    }

    /**
     * @return list<CheckResult>
     */
    private function results(Content $item, string $check): array
    {
        return array_values(array_filter($this->checker->check($item)->results, static fn (CheckResult $result): bool => $check === $result->check));
    }

    /**
     * @param array<string, string> $levels
     */
    private function levels(array $levels): void
    {
        self::getContainer()->get(Settings::class)->set(ContentChecker::LEVELS_SETTING, $levels);
    }

    private function assertBlocked(\Closure $publish): void
    {
        try {
            $publish();
            self::fail('Published despite an error.');
        } catch (InvalidContentException $e) {
            self::assertSame(['body' => 'The image “a.png” has no description. Describe it in the brackets: ![what it shows](/a.png).'], array_map(static fn (string $message): string => str_replace(['b.png', 'c.png'], 'a.png', $message), $e->violations));
        }
    }

    private function draft(string $title, string $body = '', string $type = 'post'): Content
    {
        return $this->writer->create(new CreateContent($type, $title, body: $body));
    }

    private function published(string $title, string $body = '', string $type = 'post'): Content
    {
        $item = $this->draft($title, $body, $type);

        return $this->writer->transition($item->id, $item->version, Status::PUBLISH);
    }

    private function content(Content $item): Content
    {
        return self::getContainer()->get(ContentRepository::class)->get($item->id);
    }
}
