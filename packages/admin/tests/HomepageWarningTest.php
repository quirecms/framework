<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Testing\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The settings say "an edition" while a theme that draws none serves the list: the owner is told so beside
 * the setting, as "quire:permalink:list" tells the developer.
 */
final class HomepageWarningTest extends AdminTestCase
{
    #[\Override]
    protected static ?string $theme = 'quire-fixture-base';

    private const string WARNING = 'The homepage is set to be an edition, but the theme in use draws none, so the homepage is the list.';

    public function testAnEditionWithAThemeThatDrawsNoneSaysTheHomepageIsTheList(): void
    {
        $this->signIn('admin');
        self::assertSame('edition', $this->settings()->get('site.homepage'));

        $crawler = $this->client->request('GET', '/admin/settings');
        $site = $crawler->filter('.settings-doc__part')->first();
        self::assertSame([self::WARNING], $site->filter('.settings-doc__part > .q-notice--warning')->each(static fn (Crawler $p): string => $p->text()), 'Beside the sentence that says "an edition".');
        self::assertSame(self::WARNING, $crawler->filter('#edit-setting-site-homepage .q-notice--warning')->text(), 'And in the setting\'s own small form.');

        $crawler = $this->client->request('GET', '/admin/settings/site');
        self::assertSame(self::WARNING, $crawler->filter('#setting-site-homepage-warning')->text());
        self::assertStringContainsString('setting-site-homepage-warning', (string) $crawler->filter('#setting-site-homepage')->attr('aria-describedby'));

        $this->settings()->set('site.homepage', 'listing');
        self::assertCount(0, $this->client->request('GET', '/admin/settings')->filter('.q-notice--warning'), 'The list as the homepage is what the site serves.');
    }
}
