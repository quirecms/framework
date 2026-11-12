<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The site's services for its privacy notice, from their declarations: [[privacy_services]] in a page's
 * text, or quire_privacy_services() in a template.
 */
final class ServicesListTest extends PrivacyTestCase
{
    public function testAPagesTextListsTheServices(): void
    {
        $this->content('page', 'Privacy')->slug('privacy')->body("Who we work with:\n\n[[privacy_services]]")->published()->create();

        $crawler = $this->client->request('GET', '/privacy');
        self::assertResponseIsSuccessful();
        $list = $crawler->filter('.privacy-services');

        self::assertCount(1, $list);
        self::assertSame(['Necessary', 'Preferences', 'Marketing'], $list->filter('h3')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertSame(['Fixture Spam Check', 'Fixture Fonts', 'Fixture Video'], $list->filter('h4')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertStringContainsString('player.video.example', $list->text());
        self::assertSame(['fixture_video_id', 'fixture_video_pref_*'], $list->filter('table code')->each(static fn (Crawler $code): string => $code->text()));
        self::assertSame('https://video.example/privacy', $list->filter('a[rel=noopener]')->attr('href'));
    }

    public function testATemplateListsThemToo(): void
    {
        $html = $this->service(Environment::class)->createTemplate('{{ quire_privacy_services() }}')->render();

        self::assertStringContainsString('<div class="privacy-services">', $html);
        self::assertStringContainsString('Fixture Video Ltd', $html);
    }
}
