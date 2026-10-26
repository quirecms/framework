<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Testing\AdminTestCase;

final class DashboardTest extends AdminTestCase
{
    public function testTheDashboardSummarisesContent(): void
    {
        $writer = $this->service(ContentWriter::class);
        $hello = $writer->create(new CreateContent('post', 'Hello'));
        $writer->transition($hello->id, $hello->version, Status::PUBLISH);
        $writer->create(new CreateContent('post', 'Draft post'));
        $about = $writer->create(new CreateContent('page', 'About'));
        $writer->update(new UpdateContent($about->id, $about->version)->title('About us'));
        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('title', 'Dashboard · Admin · Quire');
        self::assertSame(['Content', 'Recently edited'], $crawler->filter('.widgets .card h2')->each(static fn ($h2): string => $h2->text()));
        self::assertSame(
            [['Posts', '1', '0', '1'], ['Pages', '0', '0', '1']],
            $crawler->filter('.widgets .card:first-child tbody tr')->each(static fn ($row): array => $row->filter('th, td')->each(static fn ($cell): string => $cell->text())),
        );
        self::assertSame(['About us', 'Draft post', 'Hello'], $crawler->filter('.item-list li > :first-child')->each(static fn ($title): string => $title->text()), 'Last changed first.');
        self::assertSame('/blog/hello', $crawler->filter('.item-list a')->attr('href'), 'Published items link to their page.');
    }

    public function testThePageIsAccessibleWithoutJavaScript(): void
    {
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin');

        self::assertSame('skip-link', $crawler->filter('body a')->first()->attr('class'), 'The first link skips to the content.');
        self::assertSelectorExists('main#main');
        self::assertSelectorExists('nav.admin-nav[aria-label="Admin menu"]');
        self::assertSelectorExists('html[lang=en]');
        self::assertSelectorTextSame('.admin-bar__user span', 'Editor');
        self::assertSelectorExists('.admin-bar__user a[href^="/logout"]');
    }

    public function testThePagesLoadTurboAndStimulusFromTheSitesDownloads(): void
    {
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin');

        $importmap = json_decode($crawler->filter('script[type=importmap]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($importmap);
        self::assertIsArray($importmap['imports'] ?? null);

        foreach (['@hotwired/turbo', '@hotwired/stimulus'] as $package) {
            self::assertIsString($importmap['imports'][$package] ?? null);
            self::assertStringStartsWith('/assets/vendor/'.$package.'/', $importmap['imports'][$package], 'Downloaded into the site, not copied into the extension.');
        }

        self::assertSelectorExists('link[rel=modulepreload][href^="/assets/vendor/@hotwired/turbo/"]');
    }

    public function testTheAdminSpeaksTheSitesLanguage(): void
    {
        $this->signIn('editor');
        $this->setSetting('site.locale', 'de');
        $this->client->request('GET', '/admin');

        self::assertSelectorTextSame('main h1', 'Übersicht');
        self::assertSelectorTextSame('.skip-link', 'Zum Inhalt springen');
    }
}
