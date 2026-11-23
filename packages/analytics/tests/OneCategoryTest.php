<?php

declare(strict_types=1);

namespace Quire\Analytics\Tests;

use Quire\Analytics\AnalyticsSettings;
use Quire\Privacy\Services;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A site that asks about one category (here, Google Analytics alone) says what it asks about in the
 * banner and in the settings, in the same words, in the same order: accept, reject, then the rest.
 */
final class OneCategoryTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-privacy', 'quire-analytics'];

    public function testTheBannerAndTheSettingsNameTheCategory(): void
    {
        $this->setSetting(AnalyticsSettings::GA4_ID, 'G-ABC123DEF4');
        $this->service(Services::class)->reset();
        $crawler = $this->client->request('GET', '/');

        $texts = static fn (Crawler $buttons): array => $buttons->each(static fn (Crawler $button): string => trim($button->text()));
        self::assertSame('Analytics cookies', $crawler->filter('#quire-consent-heading')->text());
        self::assertSame(['Accept analytics cookies', 'Reject analytics cookies', 'Cookie settings'], $texts($crawler->filter('[data-consent-banner] .consent__actions button')));
        self::assertSame(['Accept analytics cookies', 'Reject analytics cookies', 'Save my choices'], $texts($crawler->filter('dialog[data-consent-dialog] .consent-dialog__actions button')));

        // Its one service: named in words under the category's switch, which is the only one to tick
        $dialog = $crawler->filter('dialog[data-consent-dialog]');
        self::assertSame('Google Analytics', $dialog->filter('.consent-dialog__service-name')->text());
        self::assertCount(1, $dialog->filter('input[type=checkbox]:not([hidden]):not([disabled])'), 'One switch: the category\'s.');
        self::assertCount(1, $dialog->filter('input[data-consent-service][hidden]'), 'The service\'s box stays, hidden, for the loader.');
    }

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }
}
