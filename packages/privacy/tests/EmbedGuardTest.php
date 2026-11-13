<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Quire\Formatter\EmbedGuardInterface;
use Quire\Formatter\Formatter;
use Quire\Privacy\Internal\Formatter\EmbedGuard;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What trusted shortcodes embed from a service visitors are asked about waits in a placeholder until
 * they allow it (doc 20, C-B). The guard fails closed: what it can't tell is held too.
 */
final class EmbedGuardTest extends PrivacyTestCase
{
    /**
     * Markup that loads from a service visitors are asked about, however it's written; and markup whose
     * source can't be told ('' for the service).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function held(): iterable
    {
        yield 'an iframe' => ['<iframe src="https://player.video.example/embed/1"></iframe>', 'fixture-video'];
        yield 'upper case' => ['<IFRAME SRC="HTTPS://PLAYER.VIDEO.EXAMPLE/embed/1"></IFRAME>', 'fixture-video'];
        yield 'protocol-relative' => ['<iframe src="//player.video.example/embed/1"></iframe>', 'fixture-video'];
        yield 'backslashes for slashes' => ['<iframe src="\\\\player.video.example/embed/1"></iframe>', 'fixture-video'];
        yield 'userinfo before the host' => ['<iframe src="https://site.example@player.video.example/embed/1"></iframe>', 'fixture-video'];
        yield 'the default port' => ['<iframe src="https://player.video.example:443/embed/1"></iframe>', 'fixture-video'];
        yield 'a trailing dot' => ['<iframe src="https://player.video.example./embed/1"></iframe>', 'fixture-video'];
        yield 'whitespace inside the scheme' => ["<iframe src=\"ht\ttps://player.video.example/embed/1\"></iframe>", 'fixture-video'];
        yield 'the second srcset candidate' => ['<img src="/a.png" srcset="/a.png 1x, https://thumbs.video.example/a.png 2x" alt="">', 'fixture-video'];
        yield 'a video\'s poster' => ['<video poster="https://thumbs.video.example/p.png" src="/v.mp4"></video>', 'fixture-video'];
        yield 'a video\'s source' => ['<video controls><source src="https://player.video.example/v.mp4"></video>', 'fixture-video'];
        yield 'a video\'s track' => ['<video controls><source src="/v.mp4"><track src="https://player.video.example/v.vtt"></video>', 'fixture-video'];
        yield 'a picture\'s source' => ['<picture><source srcset="https://thumbs.video.example/a.webp"><img src="/a.png" alt=""></picture>', 'fixture-video'];
        yield 'an object' => ['<object data="https://player.video.example/p.swf"></object>', 'fixture-video'];
        yield 'an embed' => ['<embed src="https://player.video.example/p">', 'fixture-video'];
        yield 'a script' => ['<script src="https://player.video.example/api.js"></script>', 'fixture-video'];
        yield 'a stylesheet' => ['<link rel="stylesheet" href="https://fonts.example/css?family=X">', 'fixture-fonts'];
        yield 'nested in a figure' => ['<figure><div><iframe src="https://player.video.example/embed/1"></iframe></div></figure>', 'fixture-video'];
        yield 'a frame\'s own document' => ['<iframe srcdoc="&lt;img src=https://player.video.example/x&gt;"></iframe>', ''];
        yield 'styles that import' => ['<style>@import "https://fonts.example/a.css";</style>', ''];
        yield 'styles that load' => ['<style>.a { background: url(https://thumbs.video.example/a.png) }</style>', ''];
        yield 'a host that isn\'t one' => ['<iframe src="https://%zz.video.example/embed"></iframe>', ''];
        yield 'an unknown scheme' => ['<iframe src="ftp://player.video.example/x"></iframe>', ''];
        // The architect's probe (C2c review, R1): escaped as printed, but a parser with scripting off
        // writes the attribute back unescaped, and a browser reads <noscript> as text.
        yield 'an escaped value in <noscript>' => ['<iframe src="https://player.video.example/1"></iframe><noscript><p title="&lt;/noscript&gt;&lt;/template&gt;&lt;img src=https://thumbs.video.example/leak.jpg&gt;">x</p></noscript>', ''];
        // R2: browsers and the parser read <noscript> differently, however it's written.
        yield 'markup in a <noscript> attribute' => ['<noscript><p title="</noscript><img src=https://thumbs.video.example/a.png>"></p></noscript>', ''];
        yield 'any <noscript>' => ['<noscript><p>Turn on JavaScript to see the video.</p></noscript>', ''];
    }

    #[DataProvider('held')]
    public function testEmbedsThatNeedConsentAreHeld(string $html, string $service): void
    {
        $out = $this->guard('<p>Before</p>'.$html.'<p>After</p>');
        $box = new Crawler($out)->filter('[data-consent-embed]');

        self::assertCount(1, $box, $out);
        self::assertSame($service, $box->attr('data-consent-embed'));
        // An HTML5 parser, as browsers: one element, the placeholder, and nothing that loads.
        $page = \Dom\HTMLDocument::createFromString('<!doctype html><body>'.$out, \LIBXML_NOERROR);
        $body = $page->body;
        \assert($body instanceof \Dom\HTMLElement);
        self::assertCount(1, $body->children, 'One element: the placeholder.');
        self::assertCount(0, $page->querySelectorAll('iframe, object, embed, video, img, link, style, script:not([type="application/json"])'), 'Nothing loads before consent.');
        self::assertSame('<p>Before</p>'.$html.'<p>After</p>', $this->heldOutput($out), 'What the shortcode printed, exactly, as data for the loader.');
    }

    public function testFromSeveralServicesItLoadsOnceOnly(): void
    {
        $box = new Crawler($this->guard('<iframe src="https://player.video.example/1"></iframe><link rel="stylesheet" href="https://fonts.example/a.css">'))->filter('[data-consent-embed]');

        self::assertSame('', $box->attr('data-consent-embed'), '"Always allow" one service would load the other\'s too.');
        self::assertCount(0, $box->filter('[data-consent-embed-allow]'));
    }

    public function testNothingCanEndWhatHoldsIt(): void
    {
        $out = $this->guard('<p>x</script></template><iframe src="https://player.video.example/1"></iframe></p>');
        $page = \Dom\HTMLDocument::createFromString('<!doctype html><body>'.$out, \LIBXML_NOERROR);

        self::assertCount(0, $page->querySelectorAll('iframe'), $out);
        self::assertStringNotContainsString('</script></template>', $out);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function leftAlone(): iterable
    {
        yield 'the site\'s own' => ['<iframe src="/embed/1"></iframe><img src="./a.png" alt="">'];
        yield 'a necessary service' => ['<script src="https://spam-check.example/check.js"></script>'];
        yield 'a host no service declares (the policy blocks it)' => ['<iframe src="https://elsewhere.example/embed"></iframe>'];
        yield 'a host that only ends the same' => ['<iframe src="https://evilplayer.video.example.net/embed"></iframe>'];
        yield 'a data: image' => ['<img src="data:image/png;base64,iVBORw0KGgo=" alt="">'];
        yield 'styles that load nothing' => ['<style>.a { color: red }</style>'];
        yield 'text' => ['<p>A video: <a href="https://player.video.example/watch/1">watch it there</a>.</p>'];
    }

    #[DataProvider('leftAlone')]
    public function testOtherMarkupIsLeftAsItIs(string $html): void
    {
        self::assertSame($html, $this->guard($html));
    }

    public function testThePlaceholderSaysWhatLoadingDoes(): void
    {
        $box = new Crawler($this->guard('<iframe src="https://player.video.example/embed/1" title="A video"></iframe>'))->filter('[data-consent-embed]');

        self::assertStringContainsString('This content is from Fixture Video, run by Fixture Video Ltd. Loading it sends Fixture Video Ltd your address (IP), and lets them set cookies.', $box->text());
        self::assertSame('https://video.example/privacy', $box->filter('a[rel=noopener]')->attr('href'));
        self::assertCount(1, $box->filter('[data-consent-embed-actions][hidden] [data-consent-embed-load]'), 'Hidden without JavaScript: no dead buttons.');
        self::assertSame('Always allow Fixture Video', trim($box->filter('[data-consent-embed-allow]')->text()));
        self::assertSame('<iframe src="https://player.video.example/embed/1" title="A video"></iframe>', $this->heldOutput($box->outerHtml()));
        self::assertStringNotContainsString('<iframe', $box->filter('script[data-consent-embed-content]')->text(), 'Data, with no tag in it.');
        self::assertStringContainsString('href="https://player.video.example/embed/1"', $box->filter('noscript')->html(), 'Without JavaScript, a link to it.');
        self::assertCount(1, $box->filter('[data-consent-embed-test]'), 'Outside production nothing loads, and it says so.');
    }

    public function testWhatCantBeToldOffersOnlyLoadingOnce(): void
    {
        $box = new Crawler($this->guard('<iframe srcdoc="x"></iframe>'))->filter('[data-consent-embed]');

        self::assertCount(1, $box->filter('[data-consent-embed-load]'));
        self::assertCount(0, $box->filter('[data-consent-embed-allow]'));
    }

    public function testThePlaceholderIsTheSameForEveryVisitor(): void
    {
        $html = '<iframe src="https://player.video.example/embed/1"></iframe>';

        self::assertSame($this->guard($html), $this->guard($html));
        self::assertStringNotContainsString('nonce', $this->guard($html));
    }

    public function testATrustedShortcodesEmbedIsHeldOnThePage(): void
    {
        $this->content('page', 'Videos')->slug('videos')->body("Watch:\n\n[[fixture_video id=intro]]")->published()->create();

        $crawler = $this->client->request('GET', '/videos');
        self::assertResponseIsSuccessful();
        $main = $crawler->filter('main');

        self::assertCount(1, $main->filter('[data-consent-embed="fixture-video"]'));
        $page = \Dom\HTMLDocument::createFromString((string) $this->client->getResponse()->getContent(), \LIBXML_NOERROR);
        self::assertCount(0, $page->querySelectorAll('main iframe'), 'Not before consent.');
        self::assertStringContainsString('https://player.video.example/embed/intro', $this->heldOutput($main->filter('[data-consent-embed]')->outerHtml()));
    }

    public function testRawEmbedsInStoredContentDontSurviveTheSanitiser(): void
    {
        $formatter = $this->service(Formatter::class);

        foreach (['markdown', 'html'] as $format) {
            $html = $formatter->render("A video:\n\n<iframe src=\"https://player.video.example/embed/1\"></iframe>\n\n<script src=\"https://player.video.example/api.js\"></script>", $format);

            $page = \Dom\HTMLDocument::createFromString('<!doctype html><body>'.$html, \LIBXML_NOERROR);
            self::assertCount(0, $page->querySelectorAll('iframe, script, [src]'), $format.': at most, text.');
        }
    }

    public function testQuirePrivacyFillsCoresSlot(): void
    {
        self::assertInstanceOf(EmbedGuard::class, $this->service(EmbedGuardInterface::class));
    }

    /**
     * What the placeholder holds for the loader: the JSON in its data block, decoded.
     */
    private function heldOutput(string $placeholder): string
    {
        $data = new Crawler($placeholder)->filter('script[type="application/json"][data-consent-embed-content]');
        self::assertCount(1, $data);
        $html = json_decode($data->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsString($html);

        return $html;
    }

    private function guard(string $html): string
    {
        return $this->service(EmbedGuardInterface::class)->guard($html);
    }
}
