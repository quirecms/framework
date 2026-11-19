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

    /**
     * A screen reader reads a control's help and warning by the ids its aria-describedby names: each is on
     * the page, in the settings document's small forms as on each group's page.
     */
    public function testEveryIdTheSettingsControlsAreDescribedByIsOnThePage(): void
    {
        $this->signIn('admin');
        $document = $this->client->request('GET', '/admin/settings');
        $groups = $document->filter('.settings-doc__title')->each(static fn (Crawler $h2): string => substr((string) $h2->attr('id'), \strlen('part-')));
        self::assertContains('site', $groups);

        foreach (['/admin/settings', ...array_map(static fn (string $group): string => '/admin/settings/'.$group, $groups)] as $path) {
            $crawler = $this->client->request('GET', $path);
            $described = $crawler->filter('[aria-describedby]');
            self::assertGreaterThan(0, $described->count(), $path);

            $described->each(static function (Crawler $element) use ($crawler, $path): void {
                foreach (explode(' ', (string) $element->attr('aria-describedby')) as $id) {
                    self::assertCount(1, $crawler->filter('[id="'.$id.'"]'), $path.': "'.$id.'", which '.$element->attr('id').' is described by.');
                }
            });
        }

        self::assertSame('setting-site-homepage-help setting-site-homepage-warning', $document->filter('#edit-setting-site-homepage #setting-site-homepage')->attr('aria-describedby'), 'The small form\'s control is described by its help and the warning.');
    }
}
