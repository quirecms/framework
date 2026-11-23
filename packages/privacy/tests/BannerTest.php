<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Csp\ThirdPartySourcesInterface;
use Quire\Privacy\Category;
use Quire\Privacy\Internal\ServiceSources;
use Quire\Privacy\PrivacySettings;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What the site's pages carry with services to ask about: the banner (hidden until the loader shows
 * it), the settings dialog, the loader's data, the loader, its styles, and the opener in the footer.
 */
final class BannerTest extends PrivacyTestCase
{
    public function testAPageCarriesTheBannerHiddenAndTheLoader(): void
    {
        $crawler = $this->home();

        self::assertCount(1, $crawler->filter('section.consent[data-consent-banner][hidden]'), 'Hidden: without JavaScript, no dead buttons.');
        self::assertSame('consent', 'consent consent--bar' === $crawler->filter('body > a.skip-link + *')->attr('class') ? 'consent' : $crawler->filter('body > *')->eq(1)->attr('class'), 'First after the skip link.');
        self::assertCount(1, $crawler->filter('dialog[data-consent-dialog]:not([open])'));
        self::assertCount(1, $crawler->filter('script[type=module][src^="/assets/quire-privacy/consent-"]'));
        self::assertCount(1, $crawler->filter('head link[rel=stylesheet][href^="/assets/quire-privacy/consent-"]'));
        self::assertCount(1, $crawler->filter('footer button.consent__opener[data-consent-open][hidden]'), 'Once, in the footer.');
    }

    public function testWithTwoCategoriesVisitorsAcceptAllRejectAllOrChoose(): void
    {
        $actions = $this->home()->filter('[data-consent-banner] .consent__actions button');

        self::assertSame(['Accept all', 'Reject all', 'Choose cookies'], $actions->each(static fn (Crawler $button): string => trim($button->text())));
        self::assertSame(['consent__btn', 'consent__btn'], [$actions->eq(0)->attr('class'), $actions->eq(1)->attr('class')], 'Accepting and rejecting are one component.');
    }

    public function testTheOwnerChoosesHowRejectingReads(): void
    {
        $this->setSetting(PrivacySettings::REJECT_WORDING, 'necessary');

        self::assertSame('Necessary only', trim($this->home()->filter('[data-consent-banner] [data-consent-reject]')->text()));
    }

    public function testTheDialogListsEachServiceFromItsDeclaration(): void
    {
        $dialog = $this->home()->filter('dialog[data-consent-dialog]');

        self::assertSame(['Necessary', 'Preferences', 'Marketing'], $dialog->filter('fieldset > legend')->each(static fn (Crawler $legend): string => $legend->text()));
        self::assertCount(1, $dialog->filter('input[type=checkbox][checked][disabled]'), 'Necessary is shown, always on; nothing else is ticked.');
        self::assertCount(0, $dialog->filter('input[data-consent-service][checked]'));
        self::assertStringContainsString('Fixture Video Ltd', $dialog->text());
        self::assertStringContainsString('player.video.example', $dialog->text());
        self::assertSame(['fixture_video_id', 'fixture_video_pref_*'], $dialog->filter('.consent-dialog__cookies code')->each(static fn (Crawler $code): string => $code->text()));
        self::assertSame('https://video.example/privacy', $dialog->filter('a[rel=noopener]')->attr('href'));
    }

    public function testTheDialogNamesEachCategorysSwitchAndIsDescribedByItsIntro(): void
    {
        $dialog = $this->home()->filter('dialog[data-consent-dialog]');

        self::assertSame(['Allow preferences', 'Allow marketing'], $dialog->filter('[data-consent-category]')->each(static fn (Crawler $box): string => trim($box->ancestors()->first()->text())));
        self::assertSame('quire-consent-dialog-intro', $dialog->attr('aria-describedby'));
        self::assertStringContainsString('“Cookie settings” at the bottom of every page.', $dialog->filter('#quire-consent-dialog-intro')->text());
    }

    /**
     * Under a language without texts of its own, the banner's and the dialog's are English, marked as such
     * (read as English, their punctuation where English puts it), and the loader marks what it writes.
     */
    public function testEnglishFallbacksAreMarkedForThePagesLanguage(): void
    {
        $crawler = $this->home();
        self::assertCount(0, $crawler->filter('[data-consent-banner] bdi[lang], dialog[data-consent-dialog] bdi[lang]'));
        self::assertNull($this->config($crawler)['lang'] ?? null);

        $this->setSetting('site.locale', 'ar');
        $crawler = $this->home();

        self::assertSame('en', $crawler->filter('#quire-consent-heading bdi')->attr('lang'));
        self::assertSame('en', $crawler->filter('dialog[data-consent-dialog] [data-consent-save] bdi')->attr('lang'));
        self::assertSame('en', $crawler->filter('footer .consent__opener bdi')->attr('lang'));
        self::assertSame('en', $this->config($crawler)['lang'] ?? null, 'What the loader writes (the state, what was saved).');
    }

    /**
     * After a choice the status says what was saved in a sentence, each category in its own words, which
     * agree in each language ("маркетинг исклучен", "поставки исклучени"); the footer's state names them.
     */
    public function testWhatWasSavedIsSaidInEachCategorysOwnWords(): void
    {
        $saved = $this->config($this->home())['saved'] ?? [];
        self::assertSame(['on' => 'preferences on', 'off' => 'preferences off', 'some' => 'preferences partly on'], $saved['preferences'] ?? null);
        self::assertSame('marketing off', $saved['marketing']['off'] ?? null, 'Lower case, in a sentence.');

        $this->setSetting('site.locale', 'mk');
        $saved = $this->config($this->home())['saved'] ?? [];
        self::assertSame(['маркетинг исклучен', 'поставки исклучени'], [$saved['marketing']['off'] ?? null, $saved['preferences']['off'] ?? null]);
    }

    public function testTheSitesAServiceReachesAreOnePerLine(): void
    {
        $hosts = $this->home()->filter('dialog[data-consent-dialog] ul.consent__hosts');

        $all = $hosts->filter('li > bdi[dir="ltr"]')->each(static fn (Crawler $host): string => $host->text());

        self::assertContains('player.video.example', $all);
        self::assertCount($hosts->filter('li')->count(), $all, 'Each host on its own line, read left to right.');
        self::assertStringNotContainsString(',', $hosts->text(), 'No commas.');
    }

    public function testTheLoadersDataIsTheSameForEveryVisitorAndCantEndTheScript(): void
    {
        $config = $this->config($this->home());

        self::assertSame(1, $config['v']);
        self::assertTrue($config['test'], 'Outside production nothing is sent.');
        self::assertSame(['fixture-video', 'fixture-fonts'], array_column($config['services'], 'id'));
        self::assertStringStartsWith('/assets/quire-fixture-video/start-', (string) $config['services'][0]['script']);
        self::assertSame(['channel' => 'quire'], $config['services'][0]['config']);
        self::assertSame(['fixture_video_id', 'fixture_video_pref_*'], $config['services'][0]['cookies']);
        self::assertSame('{label} is off.', $config['messages']['off']);
        self::assertSame($config['revision'], $this->config($this->home())['revision']);
        self::assertStringNotContainsString('</', $this->home()->filter('#quire-consent-config')->html(), 'Tags in the data are escaped.');
    }

    public function testTheConsentStateIsNeverPrintedByTheServerWhateverTheCookieSays(): void
    {
        $choices = [
            'none' => null,
            'all allowed' => ['fixture-video' => 'r1', 'fixture-fonts' => 'r1'],
            'all refused' => ['fixture-video' => false, 'fixture-fonts' => false],
            'broken' => 'not a choice',
        ];

        foreach ($choices as $case => $choice) {
            $this->client->getCookieJar()->clear();

            if (null !== $choice) {
                $value = \is_array($choice) ? rtrim(strtr(base64_encode(json_encode(['v' => 1, 'id' => 'x', 't' => time(), 'r' => 'r', 's' => $choice], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=') : $choice;
                $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('quire_consent', $value));
            }

            $state = $this->home()->filter('footer [data-consent-state]');
            self::assertCount(1, $state, $case);
            self::assertSame('', trim($state->text()), $case.': empty as printed (R13.1, R13.3); only the loader fills it.');
            self::assertNotNull($state->attr('hidden'), $case);
            self::assertNull($state->attr('role'), $case.': a state, not a live region (R13.4).');
            self::assertNull($state->attr('aria-live'), $case);
        }

        $config = $this->config($this->home());
        self::assertSame('{category} off', $config['messages']['stateOff'] ?? null, 'Its words, for the loader.');
        $categories = array_values(array_unique(array_column($config['services'], 'category')));
        self::assertNotSame([], $categories);

        foreach ($categories as $category) {
            self::assertSame(Category::from($category)->label(), $config['categories'][$category] ?? null, 'The categories\' own labels, for every category asked about.');
        }
    }

    public function testThePolicyAllowsTheServicesHostsButNotOnPasswordForms(): void
    {
        $this->client->request('GET', '/');
        $policy = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');

        self::assertStringContainsString('frame-src \'self\' https://player.video.example', $policy);
        self::assertStringContainsString('https://fonts.example', $policy);

        $this->client->request('GET', '/login');
        self::assertStringNotContainsString('video.example', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'), 'A script on a password form could read the password.');
    }

    /**
     * Visitors can change their choice on pages with forms for passwords or accounts (the settings and
     * the footer's opener), but nothing of a service runs there: the loader gets no service's code or
     * configuration, decided here, and the page's policy allows no other site's host.
     */
    public function testPagesWithPasswordFormsHaveTheSettingsButNoServicesCode(): void
    {
        $this->setSetting('quire.auth.password_reset', true);

        foreach (['/login', '/forgot-password', '/reset-password/'.str_repeat('a', 32).'.not-a-real-token'] as $url) {
            $crawler = $this->client->request('GET', $url);

            if (str_contains($url, 'token')) {
                // The emailed link's token moves into the session at once: the page has it nowhere
                self::assertResponseRedirects();
                $crawler = $this->client->followRedirect();
                self::assertStringNotContainsString('token', $this->client->getRequest()->getUri(), $url);
            }

            self::assertCount(0, $crawler->filter('[data-consent-banner]'), $url.': no banner asks there.');
            self::assertCount(1, $crawler->filter('footer button.consent__opener[data-consent-open]'), $url.': the opener.');
            self::assertCount(1, $crawler->filter('dialog[data-consent-dialog]'), $url.': the settings.');
            self::assertCount(1, $crawler->filter('script[type=module][src^="/assets/quire-privacy/consent-"]'), $url.': the loader, the site\'s own.');

            $config = $this->config($crawler);
            self::assertNotSame([], $config['services'], $url.': what visitors choose about.');

            foreach ($config['services'] as $service) {
                self::assertNull($service['script'], $url.': no service\'s code ('.$service['id'].').');
                self::assertSame([], $service['config'], $url.': nor its configuration.');
            }

            $policy = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
            self::assertStringNotContainsString('video.example', $policy, $url.': the policy allows no other site\'s host.');
            self::assertStringNotContainsString('fonts.example', $policy, $url);
        }

        $config = $this->config($this->home());
        self::assertNotNull($config['services'][0]['script'], 'Other pages start what visitors allow.');
    }

    public function testThePackageFillsCoresSlotForOtherSitesHosts(): void
    {
        self::assertInstanceOf(ServiceSources::class, $this->service(ThirdPartySourcesInterface::class));
    }

    private function home(): Crawler
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @return array{v: int, test: bool, revision: string, services: list<array{id: string, script: ?string, category: string, config: array<string, mixed>, cookies: list<string>}>, messages: array{off: string, stateOn?: string, stateOff?: string}, categories?: array<string, string>, lang?: ?string, saved?: array<string, array{on: string, off: string, some: string}>}
     */
    private function config(Crawler $crawler): array
    {
        /** @var array{v: int, test: bool, revision: string, services: list<array{id: string, script: ?string, category: string, config: array<string, mixed>, cookies: list<string>}>, messages: array{off: string, stateOn?: string, stateOff?: string}, categories?: array<string, string>, lang?: ?string, saved?: array<string, array{on: string, off: string, some: string}>} $config */
        $config = json_decode($crawler->filter('script#quire-consent-config')->text(), true, flags: \JSON_THROW_ON_ERROR);

        return $config;
    }
}
