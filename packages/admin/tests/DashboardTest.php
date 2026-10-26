<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Doctrine\DBAL\Connection;
use Quire\Testing\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class DashboardTest extends AdminTestCase
{
    public function testHomeSaysWhatNeedsTheUserAndHowTheWeekGoes(): void
    {
        $grace = $this->createUser('Grace Hopper', ['editor']);
        $ada = $this->signIn($this->createUser('Ada Lovelace', ['admin']));
        $now = new \DateTimeImmutable();
        $soon = min($now->modify('+1 hour'), $now->setTimezone(new \DateTimeZone('UTC'))->modify('monday next week')->setTime(0, 0)->modify('-1 minute'));

        $draw = $this->content('post', 'Spring draw')->body('Sixteen teams.')->excerpt('All groups.')->published()->create();
        $this->changes($draw)->by($grace)->body("Sixteen teams.\n\nGroup D is set.")->submitted()->create();
        $venue = $this->content('post', 'Venue guide')->body('Bus 4.')->excerpt('Getting there.')->published()->create();
        $mine = $this->changes($venue)->by($ada)->body('Bus 4 or 7.')->scheduled($soon)->create();
        $this->service(Connection::class)->update('quire.content_pending_revision', ['publish_problem' => 'Someone edited the live version first.'], ['id' => $mine->id]);
        $this->content('post', 'Ticket sales')->body("Queue early.\n\n![](/queue.png)\n")->excerpt('Tickets.')->scheduled($soon)->create();
        $this->runJobs();

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('title', 'Home · Admin · Quire');
        self::assertMatchesRegularExpression('/^Good (morning|afternoon|evening), Ada$/', $crawler->filter('h1')->text());
        self::assertMatchesRegularExpression('/^Since yesterday: .*joined and 3 things wait for you\.$/', $crawler->filter('.home-head__summary')->text(), 'Who joined, except the user.');

        // Most pressing first: what others wait for, what failed, what's better fixed before it goes live.
        self::assertSame([
            'Grace Hopper asks you to review their changes to Spring draw',
            'Scheduled changes to Venue guide couldn’t go live at '.$soon->format('H:i'),
            'Ticket sales goes live today '.$soon->format('H:i').' with 1 thing to check',
        ], $crawler->filter('.inbox__text')->each(static fn (Crawler $text): string => $text->text()));
        self::assertSame(['Review changes', 'Choose', 'Fix it'], $crawler->filter('.inbox__action')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('q-btn inbox__action', $crawler->filter('.inbox__action')->first()->attr('class'), 'The first thing to do stands out.');
        self::assertStringContainsString('merges cleanly', $crawler->filter('.inbox__item')->first()->text());
        self::assertStringEndsWith('#checks', (string) $crawler->filter('.inbox__action')->last()->attr('href'));
        self::assertSelectorTextSame('.q-rail__badge', '3, 3 things wait for you');

        // The week: what went live, and what will.
        $points = $crawler->filter('.week__point')->each(static fn (Crawler $point): string => $point->attr('class').' '.$point->filter('.week__title')->text());
        self::assertContains('week__point week__point--happened Spring draw', $points);
        self::assertNotEmpty(array_filter($points, static fn (string $point): bool => str_contains($point, '--scheduled') && str_ends_with($point, 'Ticket sales')));

        self::assertNotContains('Ada Lovelace signed in', $crawler->filter('.latest__what')->each(static fn (Crawler $what): string => $what->text()), 'Latest leaves out signing in.');
        $health = $crawler->filter('.health__sign')->each(static fn (Crawler $sign): string => $sign->text());
        self::assertSame(['Online', 'No backups yet'], [$health[0], $health[2]], 'Background tasks depend on whether a worker runs.');
    }

    public function testANewSiteStartsWithItsFirstSteps(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin');

        self::assertSelectorTextSame('h1', 'Welcome to your new site, Admin');
        self::assertSame(['Name your site', 'Write your first post', 'Invite someone to help', 'Choose a look'], $crawler->filter('.first-steps__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSelectorNotExists('.home-week');

        $this->setSetting('site.title', 'Tournament News');
        $crawler = $this->client->request('GET', '/admin');
        self::assertSelectorTextSame('.first-steps .q-meta', '1 of 4 done');
        self::assertSame('Done: Name your site', $crawler->filter('.first-steps__step--done .first-steps__title')->text());
        self::assertSame('Start writing', $crawler->filter('.first-steps a.q-btn:not(.q-btn--ghost)')->text(), 'The next step stands out.');

        // Once something is live, Home is about the site; the steps that are left stay beside it.
        $this->content('post', 'Hello')->published()->create();
        $this->client->request('GET', '/admin');
        self::assertSelectorExists('.home-week');
        self::assertSelectorTextSame('.first-steps--compact .q-meta', '2 of 4 done');
    }

    public function testThePageIsAccessibleWithoutJavaScript(): void
    {
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin');

        self::assertSame('q-skip', $crawler->filter('body a')->first()->attr('class'), 'The first link skips to the content.');
        self::assertSelectorExists('main#main');
        self::assertSelectorExists('nav.q-rail[aria-label="Admin menu"]');
        self::assertSelectorExists('html[lang=en]');
        self::assertSelectorTextSame('.q-account .q-menu__label', 'Editor');
        self::assertSelectorExists('.q-account a[href^="/logout"]');
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
        $this->content('post', 'Hallo')->published()->create();
        $this->client->request('GET', '/admin');

        self::assertMatchesRegularExpression('/^Guten (Morgen|Tag|Abend), Editor$/', $this->client->getCrawler()->filter('main h1')->text());
        self::assertSelectorTextSame('.home-week h2', 'Diese Woche');
        self::assertSelectorTextSame('.q-skip', 'Zum Inhalt springen');
    }
}
