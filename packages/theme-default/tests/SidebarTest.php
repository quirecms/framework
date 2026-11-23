<?php

declare(strict_types=1);

namespace Quire\ThemeDefault\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;

/**
 * The sidebar slot (13b): on an item's page after its text, before the way to the next one, in the text's
 * column, as a landmark of its own; on other pages, after the main content.
 */
final class SidebarTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-fixture-hello'];

    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    public function testAnItemsSidebarFollowsItsTextBeforeTheNextOne(): void
    {
        $writer = $this->service(ContentWriter::class);
        foreach (['First match' => '-2 days', 'Second match' => '-1 day'] as $title => $when) {
            $item = $writer->create(new CreateContent('post', $title, body: 'Kick-off.'));
            $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable($when));
        }

        $crawler = $this->client->request('GET', '/blog/first-match');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('aside.qd-sidebar'), 'Once.');
        self::assertCount(1, $crawler->filter('main aside.qd-sidebar[aria-label="Sidebar"] .fixture-sidebar h2'), 'In the content, a landmark with its heading.');
        self::assertCount(1, $crawler->filterXPath('//div[contains(@class, "qd-prose")]/following::aside[contains(@class, "qd-sidebar")]/following::nav[contains(@class, "qd-next")]'), 'After the text, before Newer and Older.');
        self::assertCount(1, $crawler->filter('.qd-page.qd-has-sidebar'), 'The page says it has one, for child themes.');

        $this->client->request('GET', '/blog');
        $crawler = $this->client->followRedirect();
        self::assertCount(1, $crawler->filter('main + aside.qd-sidebar .fixture-sidebar'), 'Other pages: after the main content.');
    }

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }
}
