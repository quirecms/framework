<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Testing\ExtensionTestCase;

/**
 * The package alone, with no service to ask about: visitors see nothing of it.
 */
final class NoServicesTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-privacy'];

    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    public function testNoBannerNoOpenerNoScript(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-consent-banner], [data-consent-dialog], .consent__opener, #quire-consent-config'));
        self::assertStringNotContainsString('quire-privacy/consent', (string) $this->client->getResponse()->getContent());
    }
}
