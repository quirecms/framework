<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Admin\Controller\ContentListController;
use Quire\Admin\Twig\RelativeTime;
use Quire\Content\Check\ContentChecker;
use Quire\Content\ContentRepository;
use Quire\Testing\AdminTestCase;
use Quire\Testing\Database\QueryLog;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The content screens: tabs of what needs whom, facets, next steps, acting on everything that matches,
 * the board, the calendar and the density.
 */
final class ContentScreensTest extends AdminTestCase
{
    public function testWhatNeedsWhom(): void
    {
        $kim = $this->createUser('Kim', ['editor']);
        $lin = $this->createUser('Lin', ['editor']);
        $this->setSetting(ContentChecker::LEVELS_SETTING, ['core.alt-text' => 'error']);
        $this->content('post', 'Chart')->by($kim)->body('![](/chart.png)')->create();
        $this->content('post', 'Plan')->by($kim)->create();
        $rules = $this->content('post', 'Rules')->by($kim)->published()->create();
        $hours = $this->content('post', 'Hours')->by($lin)->published()->create();
        $this->changes($rules)->by($kim)->title('Rules, new')->submitted()->create();
        $this->changes($hours)->by($lin)->title('Hours, new')->changesRequestedBy($kim, 'Add Sundays.')->create();

        $this->signIn($lin);
        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertSame(['Needs me 2', 'Everything 4'], $this->tabs($crawler), 'Kim\'s changes to review, and their own sent back.');
        $crawler = $this->client->request('GET', '/admin/content/post?view=needs-me&sort=title');
        self::assertSame(['Hours', 'Rules'], $this->titles($crawler));
        self::assertSame(['See what to change', 'Review changes'], $crawler->filter('.content-list__next .q-btn')->each(static fn (Crawler $step): string => trim(str_replace($step->filter('.q-sr')->text(), '', $step->text()))));

        $this->signIn($kim);
        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertSame(['Needs me 1', 'My drafts 2', 'Everything 4'], $this->tabs($crawler), 'Their draft that can\'t be published as it is; Lin\'s changes are Lin\'s to review.');
        self::assertSame(['Chart'], $this->titles($this->client->request('GET', '/admin/content/post?view=needs-me')));
        self::assertSame(['Chart', 'Plan'], $this->titles($this->client->request('GET', '/admin/content/post?view=mine&sort=title')));
    }

    public function testFacetsBadgesAndNextSteps(): void
    {
        $this->content('post', 'Chart')->body('![](/chart.png)')->excerpt('A chart.')->create();
        $this->content('post', 'Hello')->excerpt('Hi.')->create();
        $this->content('post', 'Hallo')->locale('de')->excerpt('Hallo.')->published()->create();
        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin/content/post?sort=title');
        self::assertSame(['Where it is', 'Something to check', 'Language'], $crawler->filter('.facet__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['any 1', 'Image without a description 1'], $this->options($crawler, 'Something to check'));
        self::assertSame(['English 2', 'German 1'], $this->options($crawler, 'Language'));
        self::assertSame(['Image without a description'], $crawler->filter('tbody .q-tag--warn')->each(static fn (Crawler $tag): string => $tag->text()), 'A finding, named after the title.');
        self::assertSame(['Fix it', '—', 'Publish'], $crawler->filter('.content-list__next')->each(static fn (Crawler $cell): string => $cell->filter('.q-btn')->count() > 0 ? trim(str_replace($cell->filter('.q-sr')->text(), '', $cell->filter('.q-btn')->text())) : $cell->text()));
        self::assertStringEndsWith('#checks', (string) $crawler->filter('.content-list__next a')->attr('href'), 'Fixing starts at the findings.');

        self::assertSame(['Chart'], $this->titles($this->client->request('GET', '/admin/content/post?f[checks][]=any')));
        self::assertSame(['Hallo'], $this->titles($crawler = $this->client->request('GET', '/admin/content/post?f[locale][]=de')));
        self::assertSame(['English 2', 'German (chosen) 1'], $this->options($crawler, 'Language'), 'Other languages still count.');
        self::assertSame(['Hallo'], $this->titles($this->client->request('GET', '/admin/content/post?q=lang:de')), 'Words that choose a facet.');
    }

    public function testActingOnEverythingThatMatches(): void
    {
        $editor = $this->signIn('editor');

        foreach (range(1, 3) as $number) {
            $this->content('post', 'Draft '.$number)->create();
        }

        $this->content('post', 'Live')->published()->create();
        $crawler = $this->client->request('GET', '/admin/content/post?f[status][]=draft');
        $form = $crawler->filter('form.content-list');
        self::assertCount(0, $form->filter('[data-action="content-list#selectMatching"]'), 'All that match are on the page already.');

        $this->client->request('POST', '/admin/content/post', ['_token' => (string) $form->filter('input[name=_token]')->attr('value'), 'state' => (string) $form->filter('input[name=state]')->attr('value'), 'action' => 'transition:publish', 'all_matching' => '1']);
        $this->client->followRedirect();
        self::assertNotice('success', 'Publish: done for 3 items.');
        self::assertSame(4, $this->service(ContentRepository::class)->query('post')->count());

        // More than a request should do: in the background, as the user who chose it.
        foreach (range(1, ContentListController::BACKGROUND_FROM + 1) as $number) {
            $this->content('post', 'More '.$number)->create();
        }

        // Their own jobs (checking each) first, so what follows is only the one to test.
        $this->runJobs(1000);

        $this->client->request('POST', '/admin/content/post', ['_token' => (string) $form->filter('input[name=_token]')->attr('value'), 'state' => (string) $form->filter('input[name=state]')->attr('value'), 'action' => 'trash', 'all_matching' => '1']);
        $this->client->followRedirect();
        self::assertNotice('success', 'Move to trash: 101 items are being done in the background. The activity log shows each one as it\'s done.');
        self::assertSame(0, $this->service(ContentRepository::class)->query('post')->withoutVisibilityScope()->onlyTrashed()->count(), 'Not yet.');

        $this->runJobs();

        self::assertSame(101, $this->service(ContentRepository::class)->query('post')->withoutVisibilityScope()->onlyTrashed()->count());
        self::assertSame($editor->id, $this->assertAudited('content.trashed')->actorId, 'As who chose it.');
    }

    public function testTheBoardAndTheCalendar(): void
    {
        $this->signIn('editor');
        $this->content('post', 'Idea')->create();
        $soon = new \DateTimeImmutable('+1 hour');
        $this->content('post', 'Coming')->scheduled($soon)->create();
        $live = $this->content('post', 'Out now')->published(new \DateTimeImmutable('-1 hour'))->create();
        $this->changes($live)->title('Out now, again')->scheduled($soon)->create();

        $board = $this->client->request('GET', '/admin/content/post?mode=board');
        self::assertSame(['Draft 1', 'In review 0', 'Scheduled 1', 'Live this week 1'], $board->filter('.content-board__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame(['Idea', 'Coming', 'Out now'], $board->filter('.content-board__card .content-list__title')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSelectorTextSame('.q-seg a[aria-current=page]', 'In motion');

        $calendar = $this->client->request('GET', '/admin/content/post?mode=calendar&month='.$soon->format('Y-m'));
        $entries = $calendar->filter('.content-calendar__entry')->each(static fn (Crawler $entry): string => $entry->attr('class').' '.$entry->filter('a')->text());

        self::assertCount(3, $entries);
        self::assertCount(1, array_filter($entries, static fn (string $entry): bool => str_contains($entry, '--scheduled') && str_contains($entry, 'Coming')));
        self::assertCount(1, array_filter($entries, static fn (string $entry): bool => str_contains($entry, '--changes') && str_contains($entry, 'Out now')), 'Changes scheduled to be published, too.');
        self::assertSelectorExists('.content-calendar__day[aria-current=date]');
    }

    public function testDensityIsRemembered(): void
    {
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertSelectorNotExists('html[data-density]');

        $this->client->submit($crawler->filter('.content-head__density button[value=compact]')->form());
        $crawler = $this->client->followRedirect();

        self::assertSelectorExists('html[data-density=compact]');
        self::assertSame('true', $crawler->filter('.content-head__density button[value=compact]')->attr('aria-pressed'));
    }

    public function testTimesAreSaidAsPeopleSayThem(): void
    {
        $this->setSetting('site.timezone', 'Europe/Berlin');
        $time = $this->service(RelativeTime::class);
        $now = new \DateTimeImmutable('2026-10-28 15:00 Europe/Berlin');

        self::assertSame([
            'just now', '5 min ago', '3 h ago', 'today 18:30', 'yesterday', 'Mon 09:00', 'Tue 09:00', '12 Sep', '12 Sep 2025',
        ], array_map(static fn (string $at): string => $time->text(new \DateTimeImmutable($at), $now), [
            '2026-10-28 14:59:30 Europe/Berlin', '2026-10-28 14:55 Europe/Berlin', '2026-10-28 12:00 Europe/Berlin', '2026-10-28 18:30 Europe/Berlin',
            '2026-10-27 23:00 Europe/Berlin', '2026-10-26 09:00 Europe/Berlin', '2026-11-03 09:00 Europe/Berlin', '2026-09-12 10:00 Europe/Berlin', '2025-09-12 10:00 Europe/Berlin',
        ]));
        self::assertSame('yesterday', $time->text(new \DateTimeImmutable('2026-10-27 22:30 UTC'), $now), 'In the site\'s time zone: 23:30 in Berlin.');
        self::assertStringContainsString('datetime="2026-10-27T22:30:00+00:00" title="2026-10-27 23:30"', (string) $time->html(new \DateTimeImmutable('2026-10-27 22:30 UTC'), $now));
    }

    public function testAListTakesAsManyQueriesForOneRowAsForMany(): void
    {
        $this->signIn('editor');
        $this->content('post', 'One')->body('![](/one.png)')->create();
        $count = function (): int {
            $log = $this->service(QueryLog::class);
            $log->start();
            $this->client->request('GET', '/admin/content/post');

            return \count($log->stop());
        };
        $one = $count();
        // The tabs, the facets (each counted without its own filter), the page, its findings, the month's count.
        self::assertLessThanOrEqual(25, $one, 'A budget for a list with facets.');

        foreach (range(2, 30) as $number) {
            $this->content('post', 'Post '.$number)->body('![](/'.$number.'.png)')->create();
        }

        self::assertSame($one, $count(), 'Facets, findings and next steps are read for the page at once.');
    }

    /**
     * @return list<string>
     */
    private function tabs(Crawler $crawler): array
    {
        return $crawler->filter('.q-views a')->each(static fn (Crawler $tab): string => $tab->text());
    }

    /**
     * @return list<string>
     */
    private function options(Crawler $crawler, string $facet): array
    {
        return $crawler->filter('.facet')->reduce(static fn (Crawler $group): bool => $group->filter('.facet__title')->count() > 0 && $facet === $group->filter('.facet__title')->text())->filter('.facet__option')->each(static fn (Crawler $option): string => $option->text());
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('.content-list__title')->each(static fn (Crawler $title): string => $title->text());
    }
}
