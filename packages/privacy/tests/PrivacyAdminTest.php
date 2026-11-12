<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Privacy\ConsentLog;
use Quire\Privacy\PrivacySettings;
use Quire\Privacy\Services;
use Quire\Testing\AdminTestCase;
use Quire\Testing\TestInstallation;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The owner's "Privacy and cookies": what visitors see, the services they're asked about with their
 * answers, necessary services that reach other sites listed apart, a preview, and a visitor's choices.
 */
final class PrivacyAdminTest extends AdminTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin', 'quire-privacy', 'quire-fixture-video'];

    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testOnlyThoseAllowedSeeIt(): void
    {
        $this->signIn('editor');
        $this->client->request('GET', '/admin/privacy');

        self::assertResponseStatusCodeSame(403);
    }

    public function testItSaysWhatVisitorsSee(): void
    {
        $page = $this->page();

        self::assertStringContainsString('Visitors see a banner that asks about: Fixture Fonts (Preferences), Fixture Video (Marketing).', $page->filter('#privacy-what + p')->text());
        self::assertStringContainsString('Test mode', $page->filter('.q-notice--warning')->first()->text(), 'Tests run outside production.');
        self::assertStringContainsString('no privacy notice linked', $page->filter('.q-notice--warning')->last()->text());

        $this->setSetting(PrivacySettings::PRIVACY_PAGE, '/privacy');
        self::assertCount(0, $this->page()->filter('.q-notice--warning:contains("no privacy notice")'));
    }

    public function testNecessaryServicesThatReachOtherSitesAreListedApartToCheck(): void
    {
        $page = $this->page();
        $necessary = $page->filter('section[aria-labelledby=privacy-necessary] tbody tr');
        $asked = $page->filter('section[aria-labelledby=privacy-services] tbody tr');

        self::assertSame(['Fixture Spam Check'], $necessary->each(static fn (Crawler $row): string => $row->filter('td')->first()->text()));
        self::assertStringContainsString('https://spam-check.example', $necessary->text());
        self::assertStringContainsString('quire-fixture-video', $necessary->text(), 'Who declared it, to tell.');
        self::assertSame(['Fixture Fonts', 'Fixture Video'], $asked->each(static fn (Crawler $row): string => $row->filter('td')->first()->text()));
    }

    public function testTheServicesShowHowVisitorsAnswered(): void
    {
        $revision = $this->service(Services::class)->get('fixture-video')?->revision() ?? self::fail('No service.');
        $this->service(ConsentLog::class)->record('11111111111111111111111111111111', ['fixture-video' => $revision]);
        $this->service(ConsentLog::class)->record('22222222222222222222222222222222', ['fixture-video' => false, 'fixture-fonts' => false]);

        $video = $this->page()->filter('section[aria-labelledby=privacy-services] tbody tr:contains("Fixture Video")')->filter('td.q-num');

        self::assertSame(['1', '1'], $video->each(static fn (Crawler $cell): string => $cell->text()));
    }

    public function testThePreviewShowsTheBannerAndTheSettingsInTheSitesLookWithoutScripts(): void
    {
        $frames = $this->page()->filter('iframe.privacy-admin__preview');

        self::assertCount(2, $frames);

        foreach ($frames as $frame) {
            \assert($frame instanceof \DOMElement);
            self::assertSame('', $frame->getAttribute('sandbox'), 'No scripts, no same origin.');
            $document = new Crawler($frame->getAttribute('srcdoc'));
            self::assertCount(0, $document->filter('script'));
            self::assertCount(1, $document->filter('link[rel=stylesheet][href*="quire-privacy/consent-"]'));
        }

        $banner = new Crawler($frames->eq(0)->attr('srcdoc') ?? '');
        $dialog = new Crawler($frames->eq(1)->attr('srcdoc') ?? '');
        self::assertCount(1, $banner->filter('[data-consent-banner]:not([hidden])'), 'Shown, as on a first visit.');
        self::assertCount(1, $dialog->filter('dialog[open][data-consent-dialog]'));
    }

    public function testAVisitorsChoicesAreFoundByTheirId(): void
    {
        $id = 'abcdefabcdefabcdefabcdefabcdef01';
        $this->service(ConsentLog::class)->record($id, ['fixture-video' => false]);

        $choices = $this->page('?consent='.strtoupper($id))->filter('section[aria-labelledby=privacy-proof] .q-card');

        self::assertCount(1, $choices);
        self::assertStringContainsString('Fixture Video (Fixture Video Ltd): refused', $choices->text());
        self::assertStringContainsString('Fixture Fonts (Fixture Fonts Inc.): not asked', $choices->text());

        self::assertStringContainsString('No choice with this ID is kept.', $this->page('?consent=00000000000000000000000000000000')->text());
        self::assertStringContainsString('32 characters', $this->page('?consent=me@example.com')->text());
    }

    public function testItIsInTheMenuAndItsSettingsAreThere(): void
    {
        $page = $this->page();

        self::assertCount(1, $page->filter('a[href="/admin/privacy"][aria-current]'));
        self::assertSame('/admin/settings/quire-privacy', $page->filter('a.q-btn:contains("Settings")')->attr('href'));

        $this->client->request('GET', '/admin/settings/quire-privacy');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name*="log_months"]');
    }

    private function page(string $query = ''): Crawler
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/privacy'.$query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
