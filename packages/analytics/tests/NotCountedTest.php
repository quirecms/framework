<?php

declare(strict_types=1);

namespace Quire\Analytics\Tests;

use Quire\Formatter\Formatter;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Pages that aren't counted say so in their head, so providers send nothing there; and only templates
 * can mark events.
 */
final class NotCountedTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-privacy', 'quire-analytics'];

    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testAPublishedPageIsCounted(): void
    {
        $this->content('page', 'About')->slug('about')->published()->create();

        $crawler = $this->client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('meta[name="quire-analytics"]'));
    }

    public function testAPreviewIsnt(): void
    {
        $this->content('page', 'Draft')->slug('draft')->create();
        $this->signIn('admin');

        $crawler = $this->client->request('GET', '/draft');

        self::assertResponseIsSuccessful();
        self::assertSame('off', $crawler->filter('head meta[name="quire-analytics"]')->attr('content'));
    }

    public function testNorAnErrorPage(): void
    {
        $request = Request::create('/missing');
        $request->attributes->set('exception', new \RuntimeException('Not found'));
        $this->service(RequestStack::class)->push($request);

        try {
            self::assertStringContainsString('<meta name="quire-analytics" content="off">', $this->service(\Twig\Environment::class)->createTemplate("{{ quire_slot('head') }}")->render());
        } finally {
            $this->service(RequestStack::class)->pop();
        }
    }

    public function testContentCantMarkEvents(): void
    {
        $formatter = $this->service(Formatter::class);

        foreach (['markdown', 'html'] as $format) {
            $html = $formatter->render('<a href="/contact" data-quire-event="contact_click" data-quire-event-place="body">Contact</a>', $format);

            $page = \Dom\HTMLDocument::createFromString('<!doctype html><body>'.$html, \LIBXML_NOERROR);
            self::assertCount(0, $page->querySelectorAll('[data-quire-event]'), $format.': only templates mark events, not what editors write.');
        }
    }
}
