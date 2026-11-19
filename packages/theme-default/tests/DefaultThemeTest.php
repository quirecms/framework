<?php

declare(strict_types=1);

namespace Quire\ThemeDefault\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Testing\ExtensionTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class DefaultThemeTest extends ExtensionTestCase
{
    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    public function testAPostPageIsAccessibleAndStyled(): void
    {
        $this->setSetting('site.title', 'Tournament and News');
        $this->publish('post', 'Hello world', "Some *text*.\n\n## A heading", '2025-03-04 10:00');

        $crawler = $this->client->request('GET', '/blog/hello-world');

        self::assertResponseIsSuccessful();
        self::assertSame('skip-link', $crawler->filter('body a')->first()->attr('class'), 'The first link skips to the content.');
        self::assertSame('#content', $crawler->filter('.skip-link')->attr('href'));
        self::assertSelectorExists('header.masthead');
        self::assertSelectorExists('main#content');
        self::assertSelectorExists('footer.site-footer');
        self::assertSelectorTextSame('main h1', 'Hello world');
        self::assertSelectorTextSame('main .kicker time[datetime="2025-03-04T10:00:00+00:00"]', 'March 4, 2025');
        self::assertSelectorTextContains('main .kicker', '1 min read');
        self::assertSelectorTextSame('main .prose em', 'text');
        self::assertSelectorExists('html[lang=en][dir=ltr]');
        self::assertSelectorExists('body.accent-blue');
        self::assertCount(1, $crawler->filter('head link[rel=stylesheet][href^="/assets/quire-theme-default/style-"]'));
        self::assertCount(3, $crawler->filter('head link[rel=preload][as=font][crossorigin]'), 'The first screen\'s fonts, preloaded.');
        self::assertSame('TN', trim($crawler->filter('.brand .monogram')->text()), 'The mark made from the site\'s name, skipping small words.');
    }

    public function testAPostSaysWhereItLivesAndWhatsAround(): void
    {
        $this->publish('post', 'First', 'One.', '2025-01-10 10:00');
        $this->publish('post', 'Middle', "Intro.\n\n## One\n\nA.\n\n## Two\n\nB.\n\n### Three\n\nC.", '2025-02-10 10:00');
        $this->publish('post', 'Last', 'Three.', '2025-03-10 10:00');

        $crawler = $this->client->request('GET', '/blog/middle');

        self::assertSame(['Posts', 'February 2025'], $crawler->filter('nav.crumbs a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('/blog#m-2025-02', $crawler->filter('nav.crumbs a')->last()->attr('href'), 'The month on the list page where it starts.');
        self::assertSame(['#h-one', '#h-two', '#h-three'], $crawler->filter('nav.toc--wide a')->each(static fn (Crawler $a): string => (string) $a->attr('href')), 'Three headings: "On this page", from the very HTML shown.');
        self::assertCount(1, $crawler->filter('nav.toc--wide li.sub'));
        self::assertSelectorTextSame('nav.next .newer .next__title', 'Last');
        self::assertSelectorTextSame('nav.next .older .next__title', 'First');
        self::assertSelectorTextContains('p.browse', 'More from February 2025');

        $crawler = $this->client->request('GET', '/blog/first');
        self::assertCount(0, $crawler->filter('nav.toc'), 'No headings, no contents.');
        self::assertCount(0, $crawler->filter('nav.next .older a'));
    }

    public function testWithTheListHomepageTheEditionLeadsAndTheArchiveGoesOnFromPageTwo(): void
    {
        // The default before the edition was: sites installed then keep it
        $this->setSetting('site.homepage', 'listing');
        $this->setSetting('quire.content.per_page', 4);
        $this->publish('post', 'In September', 'Old.', '2025-09-20 10:00');
        $this->publish('post', 'In October', 'Older.', '2025-10-20 10:00');
        $this->publish('post', 'Earlier that week', 'Week.', '2025-11-11 10:00');
        $this->publish('post', 'The lead', 'Newest.', '2025-11-14 10:00');
        $this->publish('post', 'Oldest', 'First.', '2025-08-01 10:00');

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('h1.visually-hidden', 'The homepage has a heading for screen readers.');
        self::assertSelectorTextSame('.lead h2 a', 'The lead');
        self::assertSame(['Earlier that week'], $crawler->filter('.week .week__items a')->each(static fn (Crawler $a): string => $a->text()), 'The rest of the lead\'s week, not the clock\'s.');
        self::assertSelectorTextSame('.week h2', 'Nov 8 – Nov 14', 'Named by its dates.');
        self::assertSame(['In October', 'In September'], $crawler->filter('.earlier .row h3 a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame(['October 2025', 'September 2025'], $crawler->filter('.earlier .month__head')->each(static fn (Crawler $h): string => $h->text()));
        self::assertCount(0, $crawler->filter('nav.pager'), 'The edition has no pager.');
        self::assertSame('/?page=2', $crawler->filter('.to-archive a')->attr('href'), 'The list is the home page, its one address: the archive goes on from page 2.');
        self::assertCount(0, $crawler->filter('.week__divider'), 'No "Coming up": announced posts aren\'t built.');

        $crawler = $this->client->request('GET', '/?page=2');

        self::assertSelectorTextSame('main h1', 'All posts');
        self::assertSelectorTextSame('.list-head .meta', 'Page 2 of 2 · August 2025');
        self::assertSelectorTextSame('.pager .count', 'Page 2 of 2');
        self::assertSame('/', $crawler->filter('.pager a[rel=prev]')->attr('href'));
        $months = $crawler->filter('nav.months a');
        self::assertSame(['/#m-2025-11', '/#m-2025-10', '/#m-2025-09', '/?page=2#m-2025-08'], $months->each(static fn (Crawler $a): string => (string) $a->attr('href')), 'Each month links to the page where it starts.');
        self::assertSame(['August 2025, on this page'], $crawler->filter('nav.months a[aria-current]')->each(static fn (Crawler $a): string => (string) $a->attr('aria-label')));
    }

    public function testWithTheEditionHomepageAllPostsIsTheListFromPageOne(): void
    {
        $this->setSetting('quire.content.per_page', 2);
        $this->publish('post', 'In October', 'Older.', '2025-10-20 10:00');
        $this->publish('post', 'The lead', 'Newest.', '2025-11-14 10:00');
        $this->publish('post', 'Oldest', 'First.', '2025-08-01 10:00');
        self::assertSame('edition', $this->settings()->get('site.homepage'), 'The default for new sites.');

        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextSame('.lead h2 a', 'The lead');
        self::assertSame('/blog', $crawler->filter('.to-archive a')->attr('href'), '"All posts" opens the list at its first page.');
        self::assertSame('/blog#m-2025-11', $crawler->filter('nav.months a')->first()->attr('href'));

        $crawler = $this->client->request('GET', '/blog');
        self::assertSelectorTextSame('main h1', 'All posts');
        self::assertSelectorTextSame('.pager .count', 'Page 1 of 2');
        self::assertSame(['The lead', 'In October'], $crawler->filter('.earlier .row h3 a')->each(static fn (Crawler $a): string => $a->text()), 'The archive lists the edition\'s posts too.');

        $crawler = $this->client->request('GET', '/blog/the-lead');
        self::assertSame('/blog', $crawler->filter('nav.crumbs a')->first()->attr('href'));
        self::assertSame('/blog#m-2025-11', $crawler->filter('nav.crumbs a')->last()->attr('href'));

        // Two pages, two addresses: the edition's own and the list's own
        $crawler = $this->client->request('GET', '/');
        self::assertSame('http://localhost/', $crawler->filter('head link[rel=canonical]')->attr('href'), 'The edition is a page of its own, not the list\'s first.');
        $crawler = $this->client->request('GET', '/blog');
        self::assertSame('http://localhost/blog', $crawler->filter('head link[rel=canonical]')->attr('href'));
        $this->client->request('GET', '/?page=2');
        self::assertResponseRedirects('/blog?page=2', 302, 'The edition has no pages: they\'re the list\'s (temporary: it follows a setting).');
        $this->client->request('GET', '/?page=1');
        self::assertResponseRedirects('/', 302);

        // The list keeps an address while the homepage is its edition, both ways
        foreach ([['quire.listings.post', '', 'whose list needs an address of its own'], ['site.homepage', 'listing', null], ['quire.listings.post', '', null], ['site.homepage', 'edition', 'set "quire.listings.post" first']] as [$key, $value, $fails]) {
            try {
                $this->setSetting($key, $value);
                self::assertNull($fails, $key.' should fail.');
            } catch (\Quire\Settings\Exception\InvalidSettingValueException $e) {
                self::assertNotNull($fails, $e->getMessage());
                self::assertStringContainsString($fails, $e->getMessage());
            }
        }
    }

    public function testSwitchingTheHomepageBothWaysLeavesNoRedirectLoop(): void
    {
        $this->setSetting('quire.content.per_page', 1);
        $this->publish('post', 'Older', 'A.', '-2 days');
        $this->publish('post', 'Newer', 'B.', '-1 day');

        // Each address a browser may have kept a redirect for, in either mode, ends on a page
        $addresses = ['/', '/?page=2', '/blog', '/blog?page=2'];

        foreach (['edition', 'listing', 'edition'] as $mode) {
            $this->setSetting('site.homepage', $mode);

            foreach ($addresses as $address) {
                $seen = [];
                $this->client->request('GET', $address);

                while ($this->client->getResponse()->isRedirection()) {
                    $status = $this->client->getResponse()->getStatusCode();
                    $location = (string) $this->client->getResponse()->headers->get('Location');
                    self::assertNotContains($location, $seen, \sprintf('%s loops in %s mode.', $address, $mode));
                    self::assertSame(302, $status, \sprintf('%s → %s follows the homepage setting: never kept for good.', $address, $location));
                    $seen[] = $location;
                    $this->client->request('GET', $location);
                }

                self::assertResponseIsSuccessful(\sprintf('%s in %s mode.', $address, $mode));
            }
        }
    }

    public function testAMonthSplitAcrossPagesIsContinued(): void
    {
        $this->setSetting('quire.content.per_page', 1);
        $this->publish('post', 'Late March', 'B.', '2025-03-20 10:00');
        $this->publish('post', 'Early March', 'A.', '2025-03-02 10:00');

        $this->client->request('GET', '/blog?page=2');

        self::assertSelectorTextSame('.month__head', 'March 2025 continued');
    }

    public function testAnEmptyHomeIsCalmForVisitors(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.welcome h1', 'Nothing here yet.');
        self::assertCount(0, $crawler->filter('.steps'), 'No setup words for visitors.');
    }

    public function testThemeSettings(): void
    {
        $this->setSetting('theme.quire-theme-default.accent', 'green');
        $this->setSetting('theme.quire-theme-default.show_dates', false);
        $this->publish('post', 'Hello world');

        $this->client->request('GET', '/blog/hello-world');

        self::assertSelectorExists('body.accent-green');
        self::assertSelectorNotExists('main time');
        self::assertSelectorTextContains('.site-footer .runs', 'Runs on Quire');

        $this->setSetting('theme.quire-theme-default.show_credit', false);
        $this->client->request('GET', '/blog/hello-world');
        self::assertSelectorNotExists('.site-footer .runs');
    }

    public function testPagesHaveSeoTagsAndStructuredData(): void
    {
        $this->setSetting('site.description', 'Notes on building things.');
        $this->publish('post', 'Hello world', 'A post about **things**.', '2025-03-04 10:00');

        $crawler = $this->client->request('GET', '/blog/hello-world');

        self::assertSame('A post about things.', $crawler->filter('head meta[name=description]')->attr('content'));
        self::assertSame('http://localhost/blog/hello-world', $crawler->filter('head link[rel=canonical]')->attr('href'));
        self::assertSame('article', $crawler->filter('head meta[property="og:type"]')->attr('content'));

        $data = json_decode($crawler->filter('head script[type="application/ld+json"]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(['BlogPosting', 'Hello world', 'http://localhost/blog/hello-world'], [$data['@type'] ?? null, $data['headline'] ?? null, $data['url'] ?? null]);
    }

    public function testTheThemeSpeaksTheSitesLanguage(): void
    {
        $this->setSetting('site.locale', 'de');
        $post = $this->publish('post', 'Hallo Welt', 'Text.', '2025-03-04 10:00', locale: 'de');

        $this->client->request('GET', '/blog/hallo-welt');

        self::assertSelectorExists('html[lang=de]');
        self::assertSelectorTextSame('.skip-link', 'Zum Inhalt springen');
        self::assertSelectorTextSame('main .kicker time', '4. März 2025');
        self::assertSelectorTextContains('nav.crumbs', 'März 2025');
        self::assertSelectorTextContains('.site-footer .runs', 'Betrieben mit Quire');
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

    public function testAPageHasItsSectionAndDate(): void
    {
        $writer = $this->service(ContentWriter::class);
        $about = $this->publish('page', 'About', 'Us.');
        foreach (['Venues', 'Rules'] as $title) {
            $child = $writer->create(new CreateContent('page', $title, body: 'Text.', parentId: $about->id));
            $writer->transition($child->id, $child->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));
        }

        $crawler = $this->client->request('GET', '/about');
        self::assertSame(['Venues', 'Rules'], $crawler->filter('.section-map h3')->each(static fn (Crawler $h): string => $h->text()));
        self::assertSelectorTextContains('.page-meta', 'Last updated');

        $crawler = $this->client->request('GET', '/about/venues');
        self::assertSame(['About'], $crawler->filter('nav.crumbs a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSelectorTextSame('nav.crumbs [aria-current=page]', 'Venues');
        self::assertSelectorTextSame('.in-section h2', 'Also in About');
        self::assertSame(['Rules'], $crawler->filter('.in-section b')->each(static fn (Crawler $b): string => $b->text()));
    }

    public function testTheThemeHasItsOwnErrorPages(): void
    {
        $this->publish('post', 'Latest news', 'Text.', '2025-03-04 10:00');
        $twig = $this->service(Environment::class);

        $html = $twig->render('@Twig/Exception/error404.html.twig', ['status_code' => 404, 'status_text' => 'Not Found']);
        $page = new Crawler($html);

        self::assertSame('Page not found', $page->filter('h1')->text());
        self::assertStringContainsString('class="skip-link"', $html, 'In the theme\'s layout.');
        self::assertSame(['Latest news'], $page->filter('.earlier .row h3 a')->each(static fn (Crawler $a): string => $a->text()), 'The latest posts, in the same rows as the home page.');
        self::assertCount(0, $page->filter('.suggestions, [data-slot="error404.suggestions"]'), 'An empty slot renders nothing.');

        $html = $twig->render('@Twig/Exception/error.html.twig', ['status_code' => 500, 'status_text' => 'Internal Server Error']);
        $page = new Crawler($html);
        self::assertSame('Something went wrong', $page->filter('h1')->text());
        self::assertCount(0, $page->filter('nav.site-nav'), 'Nothing read from the database.');
        self::assertCount(0, $page->filter('.row'));
    }

    private function publish(string $type, string $title, string $body = '', string $publishedAt = '-1 hour', ?string $locale = null): Content
    {
        $writer = $this->service(ContentWriter::class);
        $item = $writer->create(new CreateContent($type, $title, body: $body, locale: $locale));

        return $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable($publishedAt));
    }
}
