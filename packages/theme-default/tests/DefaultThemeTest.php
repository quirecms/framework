<?php

declare(strict_types=1);

namespace Quire\ThemeDefault\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Testing\ExtensionTestCase;
use Twig\Environment;

final class DefaultThemeTest extends ExtensionTestCase
{
    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    public function testAPostPageIsAccessibleAndStyled(): void
    {
        $this->publish('post', 'Hello world', "Some *text*.\n\n## A heading", '2025-03-04 10:00');

        $crawler = $this->client->request('GET', '/blog/hello-world');

        self::assertResponseIsSuccessful();
        self::assertSame('skip-link', $crawler->filter('body a')->first()->attr('class'), 'The first link skips to the content.');
        self::assertSame('#content', $crawler->filter('.skip-link')->attr('href'));
        self::assertSelectorExists('header.site-header');
        self::assertSelectorExists('main#content');
        self::assertSelectorExists('footer.site-footer');
        self::assertSelectorTextSame('main h1', 'Hello world');
        self::assertSelectorTextSame('main time[datetime="2025-03-04T10:00:00+00:00"]', 'March 4, 2025');
        self::assertSelectorTextSame('main .prose em', 'text');
        self::assertSelectorExists('html[lang=en][dir=ltr]');
        self::assertSelectorExists('body.accent-blue');
        self::assertCount(1, $crawler->filter('head link[rel=stylesheet][href^="/assets/quire-theme-default/style-"]'));
    }

    public function testPagesHaveSeoTagsAndStructuredData(): void
    {
        $this->setSetting('site.description', 'Notes on building things.');
        $this->publish('post', 'Hello world', 'A post about **things**.', '2025-03-04 10:00');

        $crawler = $this->client->request('GET', '/blog/hello-world');

        self::assertSame('A post about things.', $crawler->filter('head meta[name=description]')->attr('content'));
        self::assertSame('http://localhost/blog/hello-world', $crawler->filter('head link[rel=canonical]')->attr('href'));
        self::assertSame('article', $crawler->filter('head meta[property="og:type"]')->attr('content'));
        self::assertSame('Hello world', $crawler->filter('head meta[property="og:title"]')->attr('content'));
        self::assertSame('2025-03-04T10:00:00+00:00', $crawler->filter('head meta[property="article:published_time"]')->attr('content'));

        $data = json_decode($crawler->filter('head script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['BlogPosting', 'Hello world', 'http://localhost/blog/hello-world'], [$data['@type'] ?? null, $data['headline'] ?? null, $data['url'] ?? null]);

        $crawler = $this->client->request('GET', '/');
        self::assertSame('Notes on building things.', $crawler->filter('head meta[name=description]')->attr('content'));
        self::assertStringContainsString('"@type":"WebSite"', $crawler->filter('head script[type="application/ld+json"]')->text());
    }

    public function testTheBlogListsPostsWithPagination(): void
    {
        $this->setSetting('quire.content.per_page', 1);
        $this->publish('post', 'Older', 'First.', '-2 days');
        $this->publish('post', 'Newer', 'Second.', '-1 day');

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('h1.visually-hidden', 'The homepage has a heading for screen readers.');
        self::assertSelectorTextSame('.card h2 a', 'Newer');
        self::assertSelectorTextSame('.pagination span', 'Page 1 of 2');
        self::assertSame('/?page=2', $crawler->filter('.pagination a[rel=next]')->attr('href'));
    }

    public function testTheThemeSpeaksTheSitesLanguage(): void
    {
        $this->setSetting('site.locale', 'de');
        $post = $this->publish('post', 'Hallo Welt', 'Text.', '2025-03-04 10:00', locale: 'de');

        $this->client->request('GET', '/blog/hallo-welt');

        self::assertSelectorExists('html[lang=de]');
        self::assertSelectorTextSame('.skip-link', 'Zum Inhalt springen');
        self::assertSelectorTextSame('main time', '4. März 2025');
        self::assertSelectorTextContains('.credits', 'Betrieben mit Quire');
        self::assertSame('de', $post->locale);
    }

    public function testAnItemIsShownInItsOwnLanguage(): void
    {
        $this->publish('post', 'Hallo Welt', 'Text.', '2025-03-04 10:00', locale: 'de');

        $this->client->request('GET', '/blog/hallo-welt');

        self::assertSelectorExists('html[lang=de]', 'The site is in English, the item in German.');
        self::assertSelectorTextSame('.skip-link', 'Zum Inhalt springen');
    }

    public function testRightToLeftLanguages(): void
    {
        $this->setSetting('site.locale', 'ar');
        $this->publish('page', 'About', 'Text.', locale: 'ar');

        $this->client->request('GET', '/about');

        self::assertSelectorExists('html[lang=ar][dir=rtl]');
    }

    public function testThemeSettings(): void
    {
        $this->setSetting('theme.quire-theme-default.accent', 'green');
        $this->setSetting('theme.quire-theme-default.show_dates', false);
        $this->publish('post', 'Hello world');

        $this->client->request('GET', '/blog/hello-world');

        self::assertSelectorExists('body.accent-green');
        self::assertSelectorNotExists('main time');
    }

    public function testTheThemeHasItsOwnErrorPages(): void
    {
        $twig = $this->service(Environment::class);

        $html = $twig->render('@Twig/Exception/error404.html.twig', ['status_code' => 404, 'status_text' => 'Not Found']);

        self::assertStringContainsString('<h1>Page not found</h1>', $html);
        self::assertStringContainsString('class="skip-link"', $html, 'In the theme\'s layout.');
    }

    private function publish(string $type, string $title, string $body = '', string $publishedAt = '-1 hour', ?string $locale = null): Content
    {
        $writer = $this->service(ContentWriter::class);
        $item = $writer->create(new CreateContent($type, $title, body: $body, locale: $locale));

        return $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable($publishedAt));
    }
}
