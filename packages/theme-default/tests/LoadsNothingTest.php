<?php

declare(strict_types=1);

namespace Quire\ThemeDefault\Tests;

use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;

/**
 * A site with a service from another company (here, a video player visitors are asked about) never says it
 * loads nothing from other companies: the service's hosts are in the site's policy, which core reads.
 */
final class LoadsNothingTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-privacy', 'quire-fixture-video'];

    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    public function testNotWithAServiceOfAnotherCompany(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('footer .consent__opener'), 'The site asks about the service.');
        self::assertCount(0, $crawler->filter('.qd-loads-nothing'));

        $crawler = $this->client->request('GET', '/login');
        self::assertCount(0, $crawler->filter('.qd-loads-nothing'), 'Not on its sign-in page either, though that page loads no service: the line speaks for the site.');
    }

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }
}
