<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Csp\ThirdPartySourcesInterface;
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

    public function testTheLoadersDataIsTheSameForEveryVisitorAndCantEndTheScript(): void
    {
        $config = $this->config($this->home());

        self::assertSame(1, $config['v']);
        self::assertTrue($config['test'], 'Outside production nothing is sent.');
        self::assertSame(['fixture-video', 'fixture-fonts'], array_column($config['services'], 'id'));
        self::assertStringStartsWith('/assets/quire-fixture-video/start-', $config['services'][0]['script']);
        self::assertSame(['channel' => 'quire'], $config['services'][0]['config']);
        self::assertSame(['fixture_video_id', 'fixture_video_pref_*'], $config['services'][0]['cookies']);
        self::assertSame('{label} is off.', $config['messages']['off']);
        self::assertSame($config['revision'], $this->config($this->home())['revision']);
        self::assertStringNotContainsString('</', $this->home()->filter('#quire-consent-config')->html(), 'Tags in the data are escaped.');
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

    public function testPagesWithPasswordFormsHaveNoBannerAndNoLoader(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-consent-banner], #quire-consent-config, .consent__opener, script[src*="quire-privacy/consent"], link[href*="quire-privacy/consent"]'), 'Nothing of a service runs there, whatever the visitor allowed.');
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
     * @return array{v: int, test: bool, revision: string, services: list<array{id: string, script: string, config: array<string, mixed>, cookies: list<string>}>, messages: array{off: string}}
     */
    private function config(Crawler $crawler): array
    {
        /** @var array{v: int, test: bool, revision: string, services: list<array{id: string, script: string, config: array<string, mixed>, cookies: list<string>}>, messages: array{off: string}} $config */
        $config = json_decode($crawler->filter('script#quire-consent-config')->text(), true, flags: \JSON_THROW_ON_ERROR);

        return $config;
    }
}
