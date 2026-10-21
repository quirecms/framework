<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentRenderer;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Exception\InvalidContentException;
use Quire\Fixtures\Hello\Formatter\GreetingShortcode;
use Quire\Formatter\Formatter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ContentRendererTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    private ContentRenderer $renderer;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = $container->get(ContentWriter::class);
        // As in production: with the cache (tests run in debug mode, which skips it).
        $this->renderer = new ContentRenderer($container->get(Formatter::class), new ArrayAdapter(), 'test', debug: false);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testBodyTeaserAndExcerpt(): void
    {
        $post = $this->post("Intro with **bold** text.\n\n<!-- more -->\n\nThe rest.");

        self::assertSame("<p>Intro with <strong>bold</strong> text.</p>\n<p>The rest.</p>\n", $this->renderer->body($post));
        self::assertSame("<p>Intro with <strong>bold</strong> text.</p>\n", $this->renderer->teaser($post));
        self::assertSame('Intro with bold text.', $this->renderer->excerpt($post));
        self::assertNull($this->renderer->teaser($this->post('No marker.')));
    }

    public function testExcerpts(): void
    {
        $long = $this->post('A sentence that goes on & on, and then some more words follow here. [[greeting]]');

        self::assertSame('A sentence that goes on & on, and then…', $this->renderer->excerpt($long, 40), 'Cut at a word, entities decoded, shortcodes left out.');
        self::assertSame('Written by hand', $this->renderer->excerpt($this->post('Body', excerpt: ' Written  by hand ')));
    }

    public function testTheCacheFollowsTheVersion(): void
    {
        $post = $this->post('First');
        self::assertSame("<p>First</p>\n", $this->renderer->body($post));

        // A change that bypasses the writer, without a new version, isn't seen: the cache is keyed on the version.
        $this->connection->executeStatement("UPDATE content SET body = 'Sneaky' WHERE id = ?", [$post->id]);
        self::assertSame("<p>First</p>\n", $this->renderer->body($this->reload($post)));

        $updated = $this->writer->update(new UpdateContent($post->id, $post->version)->body('Second'));
        self::assertSame("<p>Second</p>\n", $this->renderer->body($updated));
    }

    public function testShortcodesRenderEvenWhenCached(): void
    {
        // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture extension only runs in tests)
        $greeting = self::getContainer()->get(GreetingShortcode::class);
        self::assertInstanceOf(GreetingShortcode::class, $greeting);
        $post = $this->post('Hi [[greeting name=Ada]]');
        $before = $greeting->renders;

        $this->renderer->body($post);
        $html = $this->renderer->body($post);

        self::assertSame("<p>Hi <strong>Hello, Ada!</strong></p>\n", $html);
        self::assertSame($before + 2, $greeting->renders);
    }

    public function testFormatsMustBeKnown(): void
    {
        try {
            $this->writer->create(new CreateContent('post', 'Hello', bodyFormat: 'asciidoc'));
            self::fail('The format was accepted.');
        } catch (InvalidContentException $e) {
            self::assertSame(['body_format' => 'Unknown format. Use one of: markdown, text, html.'], $e->violations);
        }

        $html = $this->post('<p>Hi <em>there</em></p>', format: 'html');
        self::assertSame('<p>Hi <em>there</em></p>', $this->renderer->body($html));
    }

    private function post(string $body, ?string $excerpt = null, string $format = 'markdown'): Content
    {
        return $this->writer->create(new CreateContent('post', 'Post', body: $body, bodyFormat: $format, excerpt: $excerpt));
    }

    private function reload(Content $content): Content
    {
        return self::getContainer()->get(ContentRepository::class)->get($content->id);
    }
}
