<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Settings\Settings;
use Quire\Theme\ContentPreviews;
use Quire\Theme\Seo\SeoRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * How an item will appear, before it does: its card as the theme draws it, alone, and its tags for
 * search results and sharing, without a page request.
 */
final class ContentPreviewsTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheCardAsTheThemeDrawsIt(): void
    {
        $draft = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Spring draw: all groups', body: 'Sixteen teams.', slug: 'spring-draw', excerpt: 'All 16 teams and their groups.'));
        $previews = self::getContainer()->get(ContentPreviews::class);

        $card = $previews->card($draft);
        self::assertStringContainsString('<a href="/blog/spring-draw">Spring draw: all groups</a>', $card, 'A draft too: it\'s a preview.');
        self::assertStringContainsString('All 16 teams and their groups.', $card);

        $document = $previews->cardDocument($draft);
        self::assertStringStartsWith('<!doctype html>', $document);
        self::assertMatchesRegularExpression('~<link rel="stylesheet" href="[^"]+">~', $document, 'With the theme\'s styles.');
        self::assertStringNotContainsString('<script', $document, 'No scripts, for a sandboxed iframe.');
        self::assertStringContainsString($card, $document);
    }

    public function testTagsForSearchResultsAndSharing(): void
    {
        $settings = self::getContainer()->get(Settings::class);
        $settings->set('site.title', 'Tournament News');
        $item = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Venue guide', slug: 'venue-guide', excerpt: 'Getting to the grounds.'));
        $previews = self::getContainer()->get(ContentPreviews::class);

        $tags = $previews->seo($item);
        self::assertSame(['Venue guide', 'Getting to the grounds.', 'Tournament News', null], [$tags->title, $tags->description, $tags->siteName, $tags->imageUrl]);
        // Absolute, as search engines show it, with the site's address even without a request.
        self::assertMatchesRegularExpression('~^https?://[^/]+/blog/venue-guide$~', (string) $tags->canonicalUrl);
        $origin = (string) preg_replace('~^(https?://[^/]+).*$~', '$1', (string) $tags->canonicalUrl);

        // Pages without an image share the site's: a path is made absolute.
        $settings->set(SeoRenderer::SHARE_IMAGE_SETTING, '/images/share.png');
        self::assertSame($origin.'/images/share.png', $previews->seo($item)->imageUrl);
        $settings->set(SeoRenderer::SHARE_IMAGE_SETTING, 'https://cdn.example/share.png');
        self::assertSame('https://cdn.example/share.png', $previews->seo($item)->imageUrl);
    }
}
