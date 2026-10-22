<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Paths are kept by the database: an item's slug below its parent's path, plus the paths it had before.
 */
final class ContentPathTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->writer = self::getContainer()->get(ContentWriter::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testPathsFollowTheTree(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello world'));
        $about = $this->page('About');
        $team = $this->page('Team', $about);
        $ada = $this->page('Ada', $team);

        self::assertSame('hello-world', $post->path);
        self::assertSame('about', $about->path);
        self::assertSame('about/team', $team->path);
        self::assertSame('about/team/ada', $ada->path);
    }

    public function testRenamingOrMovingAnItemMovesItsDescendantsAndRemembersOldPaths(): void
    {
        $about = $this->page('About');
        $team = $this->page('Team', $about);
        $ada = $this->page('Ada', $team);
        $company = $this->page('Company');

        $this->writer->update(new UpdateContent($about->id, $about->version)->slug('about-us'));

        self::assertSame('about-us/team/ada', $this->reload($ada)->path);
        self::assertSame(['about/team/ada'], $this->history($ada));

        $team = $this->reload($team);
        $this->writer->update(new UpdateContent($team->id, $team->version)->parent($company->id));

        self::assertSame('company/team/ada', $this->reload($ada)->path);
        self::assertSame(['about-us/team/ada', 'about/team/ada'], $this->history($ada));
        self::assertSame(['about-us/team', 'about/team'], $this->history($team));
        self::assertSame(['about'], $this->history($about), 'Only the moved branch changes.');
    }

    public function testMovingBackForgetsTheCurrentPath(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));
        $post = $this->writer->update(new UpdateContent($post->id, $post->version)->slug('hi'));
        $post = $this->writer->update(new UpdateContent($post->id, $post->version)->slug('hello'));

        self::assertSame('hello', $post->path);
        self::assertSame(['hi'], $this->history($post), 'The current path is never an "earlier" one.');
    }

    public function testRestoringWithANewSlugUpdatesThePath(): void
    {
        $first = $this->writer->create(new CreateContent('post', 'Hello'));
        $first = $this->writer->trash($first->id, $first->version);
        $this->writer->create(new CreateContent('post', 'Hello'));

        self::assertSame('hello-2', $this->writer->restore($first->id)->path);
    }

    private function page(string $title, ?Content $parent = null): Content
    {
        return $this->writer->create(new CreateContent('page', $title, parentId: $parent?->id));
    }

    private function reload(Content $content): Content
    {
        return self::getContainer()->get(\Quire\Content\ContentRepository::class)->get($content->id);
    }

    /**
     * @return list<string> Earlier paths, newest first
     */
    private function history(Content $content): array
    {
        /** @var list<string> $paths */
        $paths = $this->connection->fetchFirstColumn('SELECT path FROM quire.content_path_history WHERE content_id = ? ORDER BY changed_at DESC, path', [$content->id]);

        return $paths;
    }
}
