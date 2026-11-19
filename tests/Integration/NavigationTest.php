<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Theme\Navigation\Navigation;
use Quire\Theme\Navigation\NavigationItem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The site's navigation, as themes read it: core's top-level pages visitors can see, in their order,
 * the page being shown and its section marked.
 */
final class NavigationTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(ContentWriter::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheVisibleTopLevelPagesInTheirOrderWithTheCurrentOneMarked(): void
    {
        $about = $this->published('About', 2);
        $team = $this->published('Team', 1, $about);
        $this->published('Contact', 3);
        $this->published('Blog home', 1);
        $draft = $this->writer->create(new CreateContent('page', 'Draft page', position: 0));

        $request = Request::create('/about/team');
        $request->attributes->set(Navigation::CONTENT_ATTRIBUTE, $team);
        $items = $this->navigation($request)->items();

        self::assertSame(['Blog home', 'About', 'Contact'], array_map(static fn (NavigationItem $item): string => $item->title, $items), 'Top-level only, by position, without the draft.');
        self::assertSame([false, 'section', false], array_map(static fn (NavigationItem $item): string|false => $item->current, $items), 'The page shown is in About\'s section.');
        self::assertNotContains($draft->id, array_map(static fn (NavigationItem $item): ?string => $item->content?->id, $items));

        $request = Request::create('/about');
        $request->attributes->set(Navigation::CONTENT_ATTRIBUTE, $about);
        self::assertSame('page', $this->navigation($request)->items()[1]->current);
    }

    public function testItIsReadOncePerRequest(): void
    {
        $this->published('About', 1);
        $navigation = $this->navigation(Request::create('/'));

        self::assertSame($navigation->items(), $navigation->items());
    }

    private function navigation(Request $request): Navigation
    {
        $stack = new RequestStack([$request]);

        return new Navigation(self::getContainer()->get(\Quire\Theme\Navigation\NavigationProviderInterface::class), $stack);
    }

    private function published(string $title, int $position, ?Content $parent = null): Content
    {
        $item = $this->writer->create(new CreateContent('page', $title, parentId: $parent?->id, position: $position));

        return $this->writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));
    }
}
