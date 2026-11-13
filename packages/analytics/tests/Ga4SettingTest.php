<?php

declare(strict_types=1);

namespace Quire\Analytics\Tests;

use Quire\Analytics\AnalyticsSettings;
use Quire\Privacy\Services;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Google Analytics is off until the owner sets its measurement ID in the admin's settings; then visitors
 * are asked about it, the policy allows Google's tag, and its ID reaches the loader. Setting it is in the
 * activity log.
 */
final class Ga4SettingTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-privacy', 'quire-analytics', 'quire-fixture-video'];

    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testOffWithoutAnId(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertNotContains(AnalyticsSettings::GA4_SERVICE, array_column($this->config($crawler)['services'], 'id'));
        self::assertStringNotContainsString('googletagmanager', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('max-age=0, must-revalidate, private', $this->client->getResponse()->headers->get('Cache-Control'), 'So turning it off takes effect with the next page (docs/analytics.md).');
        self::assertStringNotContainsString('Google Analytics', $crawler->filter('dialog[data-consent-dialog]')->text());
    }

    public function testOnWithItsId(): void
    {
        $before = $this->service(Services::class);
        $video = $before->get('fixture-video')?->revision();
        $revision = $before->revision();

        $this->setSetting(AnalyticsSettings::GA4_ID, 'G-ABC123DEF4');
        $this->setSetting(AnalyticsSettings::GA4_KEEP, 'q, page');
        $this->service(Services::class)->reset();
        $crawler = $this->client->request('GET', '/');

        $service = array_first(array_filter($this->config($crawler)['services'], static fn (array $service): bool => AnalyticsSettings::GA4_SERVICE === $service['id'])) ?? null;
        self::assertNotNull($service);
        self::assertSame(['measurement_id' => 'G-ABC123DEF4', 'keep' => 'q,page'], $service['config']);
        self::assertSame('statistics', $service['category']);
        self::assertStringContainsString('script-src \'self\' https://www.googletagmanager.com/gtag/', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('Google Ireland Limited', $crawler->filter('dialog[data-consent-dialog]')->text());

        $after = $this->service(Services::class);
        self::assertNotSame($revision, $after->revision(), 'Visitors are asked again: about Google Analytics only.');
        self::assertSame($video, $after->get('fixture-video')?->revision(), 'Their answers about the other services stand.');
    }

    public function testTheIdIsChecked(): void
    {
        $this->expectException(InvalidSettingValueException::class);

        $this->setSetting(AnalyticsSettings::GA4_ID, 'GTM-ABC123');
    }

    public function testSoAreTheParameters(): void
    {
        $this->expectException(InvalidSettingValueException::class);

        $this->setSetting(AnalyticsSettings::GA4_KEEP, 'q, <script>');
    }

    public function testChangingItIsInTheActivityLog(): void
    {
        $this->setSetting(AnalyticsSettings::GA4_ID, 'G-ABC123DEF4');
        $this->setSetting(AnalyticsSettings::GA4_ID, 'G-ZZZ999');

        $entries = $this->audited('setting.changed', AnalyticsSettings::GA4_ID);
        $entry = end($entries);
        self::assertNotFalse($entry);
        self::assertEquals(['from' => 'G-ABC123DEF4', 'to' => 'G-ZZZ999'], $entry->details, 'Who, when, and from which property to which.');
    }

    /**
     * @return array{services: list<array{id: string, category: string, config: array<string, scalar>}>}
     */
    private function config(Crawler $crawler): array
    {
        /** @var array{services: list<array{id: string, category: string, config: array<string, scalar>}>} $config */
        $config = json_decode($crawler->filter('script#quire-consent-config')->text(), true, flags: \JSON_THROW_ON_ERROR);

        return $config;
    }
}
