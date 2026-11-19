<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Quire\Fixtures\Hello\Formatter\GreetingShortcode;
use Quire\Formatter\Exception\UnknownFormatException;
use Quire\Formatter\Formatter;
use Quire\Formatter\Internal\StructureMark;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The formatter with core's formats and profiles, and the fixture extension's shortcodes and processors.
 */
final class FormatterTest extends KernelTestCase
{
    private Formatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = self::getContainer()->get(Formatter::class);
    }

    public function testMarkdown(): void
    {
        $html = $this->markdown("## Getting started\n\n- [x] Done\n- ~~old~~\n\n| a |\n|---|\n| 1 |\n\n```php\necho 1;\n```\n\nVisit https://quirecms.com");

        self::assertStringContainsString('<h2 id="h-getting-started">Getting started</h2>', $html, 'Heading ids are prefixed, so they never meet a theme\'s own.');
        self::assertStringContainsString('<input checked="" disabled="" type="checkbox"> Done', $html);
        self::assertStringContainsString('<del>old</del>', $html);
        self::assertStringContainsString('<td role="cell" data-label="a" data-kind="num">1</td>', $html);
        self::assertStringContainsString('<code class="language-php">echo 1;', $html);
        self::assertStringContainsString('<a href="https://quirecms.com">https://quirecms.com</a>', $html);
    }

    public function testUnsafeMarkupNeverSurvives(): void
    {
        $html = $this->markdown("<script>alert(1)</script>\n\n[a](javascript:alert(1)) <img src=x onerror=alert(1)>\n\n<b onclick=\"x()\">bold</b>");

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<img', $html, 'Raw HTML in Markdown is escaped by default.');
        self::assertStringContainsString('&lt;script&gt;', $html);

        $html = $this->formatter->render('<p onclick="x()">Hi <script>alert(1)</script><iframe src="https://evil.example"></iframe><a href="javascript:x()">a</a></p>', 'html');

        self::assertSame('<p>Hi <a>a</a></p>', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function attacks(): iterable
    {
        yield 'script' => ['<script>alert(1)</script>'];
        yield 'event handler' => ['<img src="x" onerror="alert(1)">'];
        yield 'javascript link' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'entity-encoded javascript' => ['<a href="&#106;avascript:alert(1)">x</a>'];
        yield 'markdown javascript link' => ['[x](javascript:alert(1)) [y](<javascript:alert(1)>) [z](JaVaScRiPt:alert(1))'];
        yield 'data uri' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a> ![i](data:image/svg+xml;base64,PHN2Zz4=)'];
        yield 'svg' => ['<svg onload="alert(1)"><script>alert(1)</script></svg>'];
        yield 'style' => ['<p style="background:url(javascript:alert(1))">x</p><style>*{}</style>'];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>'];
        yield 'form' => ['<form action="https://evil.example"><input name="password"><button>Go</button></form>'];
        yield 'shortcode attribute' => ['[[greeting name="<script>alert(1)</script>"]] [[greeting name=\'<img src=x onerror=alert(1)>\']]'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('attacks')]
    public function testAttacksAreNeutralised(string $source): void
    {
        foreach (['content' => ['markdown', 'text', 'html'], 'comment' => ['markdown', 'text'], 'review' => ['markdown']] as $profile => $formats) {
            foreach ($formats as $format) {
                $this->assertSafe($this->formatter->render($source, $format, $profile), \sprintf('The "%s" profile, format %s', $profile, $format));
            }
        }
    }

    public function testLongTextIsNotCut(): void
    {
        $long = str_repeat('word ', 10_000);

        // The sanitiser's default limit (20 000 characters) would silently cut it.
        self::assertStringEndsWith("word</p>\n", $this->formatter->render($long, 'text'));
        self::assertGreaterThan(49_000, \strlen($this->markdown($long)));
    }

    public function testShortcodes(): void
    {
        $html = $this->markdown("Say [[greeting name=\"Ada Lovelace\"]] and `[[greeting]]`.\n\n[[greeting name=Grace]]\n\n[[unknown x=1]]\n\n    [[greeting]]");

        self::assertStringContainsString('<p>Say <strong>Hello, Ada Lovelace!</strong> and <code>[[greeting]]</code>.</p>', $html, 'Inline, and literal in code spans.');
        self::assertStringContainsString("\n<strong>Hello, Grace!</strong>\n", $html, 'Alone in a paragraph, it replaces the paragraph.');
        self::assertStringContainsString('<p>[[unknown x=1]]</p>', $html, 'Unknown shortcodes stay as written.');
        self::assertStringContainsString("aria-label=\"Code\"><code>[[greeting]]\n</code></pre>", $html, 'Literal in code blocks.');
    }

    public function testShortcodeOutputIsSanitisedUnlessTrusted(): void
    {
        self::assertSame('Hi <strong>Hello, world!</strong>', trim(strip_tags($this->markdown('Hi [[greeting evil]]'), '<strong>')));
        self::assertStringNotContainsString('<script', $this->markdown('Hi [[greeting evil]]'));
        self::assertStringContainsString('<iframe src="https://video.example/abc"></iframe>', $this->markdown('[[embed id=abc]]'));
    }

    public function testPlaceholdersCantBeForgedOrPlacedInAttributes(): void
    {
        $forged = $this->formatter->compile("Hi \u{E000}0\u{E001} [[greeting]]", 'markdown');
        self::assertCount(1, $forged->shortcodes);
        self::assertSame('<p>Hi 0 <strong>Hello, world!</strong></p>', trim($this->formatter->expand($forged)));

        $html = $this->formatter->render('<a href="/x" title="[[embed id=1]]">link</a>', 'html');
        self::assertSame('<a href="/x" title="">link</a>', $html, 'Output can\'t break out of an attribute.');
    }

    public function testShortcodesRunOnEveryExpansion(): void
    {
        // @phpstan-ignore symfonyContainer.serviceNotFound (the fixture extension only runs in tests)
        $greeting = self::getContainer()->get(GreetingShortcode::class);
        self::assertInstanceOf(GreetingShortcode::class, $greeting);
        $compiled = $this->formatter->compile('[[greeting]] [[greeting]]', 'markdown');
        $before = $greeting->renders;

        $this->formatter->expand($compiled);
        $this->formatter->expand($compiled);

        self::assertSame($before + 4, $greeting->renders);
    }

    public function testFailingShortcodesAreLoggedOutsideDebugMode(): void
    {
        $container = self::getContainer();
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $replacements = [];

                foreach ($context as $key => $value) {
                    $replacements['{'.$key.'}'] = \is_string($value) ? $value : '';
                }

                $this->messages[] = strtr((string) $message, $replacements);
            }
        };
        $services = new readonly class($container) implements ContainerInterface {
            public function __construct(private \Symfony\Component\DependencyInjection\ContainerInterface $container)
            {
            }

            public function get(string $id): mixed
            {
                return $this->container->get($id);
            }

            public function has(string $id): bool
            {
                return $this->container->has($id);
            }
        };
        /** @var array{formats: array<string, array{class: string, owner: string}>, profiles: array<string, array<string, mixed>>, shortcodes: array<string, array{class: string, trusted: bool, owner: string}>, commonmark: list<array{class: string, profiles: ?list<string>, owner: string}>, pre: list<array{class: string, profiles: ?list<string>, owner: string}>, post: list<array{class: string, profiles: ?list<string>, owner: string}>} $config */
        $config = $container->getParameter(Formatter::PARAMETER);

        $html = new Formatter($config, $services, $logger, debug: false)->render('A [[greeting fail]] B', 'markdown');

        self::assertSame('<p>A  B</p>', trim($html));
        self::assertSame(['The shortcode [[greeting fail]] failed: The greeting failed.'], $logger->messages);
    }

    public function testTheCommentProfileAllowsBasicMarkupOnly(): void
    {
        $html = $this->formatter->render("## Title\n\n**Bold** [link](https://example.com) ![img](https://example.com/a.png) [[greeting]]", 'markdown', 'comment');

        self::assertStringContainsString('Title', $html);
        self::assertStringNotContainsString('<h2', $html);
        self::assertStringContainsString('<strong>Bold</strong>', $html);
        self::assertStringContainsString('<a href="https://example.com" rel="nofollow ugc">link</a>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('[[greeting]]', $html, 'The profile allows no shortcodes.');

        $this->expectException(UnknownFormatException::class);
        $this->expectExceptionMessageIsOrContains('The "comment" profile doesn\'t allow the format "html". Use one of: markdown, text.');
        $this->formatter->render('<p>x</p>', 'html', 'comment');
    }

    public function testExtensionProfilesAndProcessors(): void
    {
        $content = $this->markdown(":wave: ![a](/a.png) Note[^1]\n\n[^1]: The note.");
        self::assertStringContainsString('👋', $content, 'Pre-processors run for every profile.');
        self::assertStringContainsString('<img loading="lazy" src="/a.png" alt="a">', $content, 'This post-processor only runs for "content".');
        self::assertStringContainsString('The note.', $content);
        self::assertStringContainsString('role="note"', $content, 'Footnotes only run for "content", where each is set beside its reference.');

        $review = $this->formatter->render(':wave: [[greeting]] [[embed id=1]] Note[^1]', 'markdown', 'review');
        self::assertSame('<p>👋 <strong>Hello, world!</strong> [[embed id&#61;1]] Note[^1]</p>', trim($review));
    }

    public function testHeadingsGetALinkBesideThemNotInThem(): void
    {
        $html = $this->markdown("## How it works\n\nText.\n\n### Content");

        self::assertStringContainsString('<div class="heading-row"><h2 id="h-how-it-works">How it works</h2><a class="heading-anchor" href="#h-how-it-works" aria-label="Link to the section “How it works”">#</a></div>', $html);
        self::assertStringContainsString('<h3 id="h-content">Content</h3>', $html, 'A heading "Content" never takes a theme\'s id "content".');
    }

    public function testFootnotesAreSetBesideTheirFirstReference(): void
    {
        $html = $this->markdown("First.[^a] Second.[^b] Again.[^a]\n\n[^a]: Note *A*.\n\n    Its second paragraph.\n\n[^b]: Note B, voilà.");

        self::assertStringNotContainsString('doc-endnotes', $html, 'No list of notes at the end.');
        self::assertStringContainsString('First.<sup><a href="#fn-a" aria-label="Note 1" id="fnref-1">1</a></sup><span class="footnote" id="fn-a" role="note"><b><span class="visually-hidden">Note </span>1</b> Note <em>A</em>.', $html);
        self::assertStringContainsString('Its second paragraph.</span>', $html, 'Its paragraphs read on one line, without back-links.');
        self::assertStringContainsString('<span class="footnote" id="fn-b" role="note"><b><span class="visually-hidden">Note </span>2</b> Note B, voilà.</span>', $html, 'The text is cut by characters, never bytes.');
        self::assertStringContainsString('Again.<sup><a href="#fn-a" aria-label="Note 1">1</a></sup></p>', $html, 'A second reference links to the note, once.');
        self::assertSame(1, substr_count($html, 'id="fn-a"'));
    }

    public function testFootnotesNeverInVisitorsComments(): void
    {
        $html = $this->formatter->render("Hi.[^1]\n\n[^1]: A note.", 'markdown', 'comment');

        self::assertStringNotContainsString('footnote', $html);
        self::assertStringNotContainsString('role=', $html, 'Visitors\' text gets none of the structure.');
    }

    public function testTablesTellThemesWhatTheyHoldNotHowToLookOrSoundAsAnything(): void
    {
        $html = $this->markdown("## Round 1 results\n\n| Date | Home | Score | Away | Crowd |\n|---|---|---|---|---|\n| Fri, Mar 19 | FC Harbour | 2–1 | Northside | 3,960 |\n| Sat, Mar 20 | Moor Lane | 0-0 | Crown Park | 2,140 |");

        self::assertStringContainsString('<div class="table" role="region" tabindex="0" aria-labelledby="h-round-1-results"><table role="table" data-columns="5">', $html, 'A wide table scrolls by keyboard, named by its heading.');
        self::assertStringContainsString('<th role="columnheader" scope="col">Date</th>', $html);
        self::assertStringContainsString('<th scope="row" role="rowheader" data-label="Date">Fri, Mar 19</th>', $html, 'The first cell names its row.');
        self::assertStringContainsString('<td role="cell" data-label="Score" data-kind="pair">2–1</td>', $html);
        self::assertStringContainsString('<td role="cell" data-label="Crowd" data-kind="num">3,960</td>', $html);
        self::assertStringContainsString('<th role="columnheader" scope="col" data-kind="num">Crowd</th>', $html);
        self::assertStringContainsString('<td role="cell" data-label="Home">FC Harbour</td>', $html, 'Text columns get no kind.');

        self::assertStringContainsString('aria-label="Table 1"', $this->markdown("| a | b |\n|---|---|\n| x | y |"), 'A table without a heading before it is named by its number.');
    }

    public function testCodeBlocksAreNamedRegionsThatScrollByKeyboard(): void
    {
        self::assertStringContainsString('<pre role="region" tabindex="0" aria-label="Code: php"><code class="language-php">', $this->markdown("```php\necho 1;\n```"));
        self::assertStringContainsString('<pre role="region" tabindex="0" aria-label="Code"><code>', $this->markdown('    indented'));
    }

    public function testAnAuthorsMarkupCantPassForTheRenderersOrTakeItsIds(): void
    {
        // Forged footnotes and headings, an id the renderer or a script uses, and roles
        $html = $this->formatter->render('<p>x<sup id="fnref-1"><a href="#fn-1" role="doc-noteref">1</a></sup> <a href="#top">top</a></p><div role="doc-endnotes"><ol><li id="fn-1" role="doc-endnote">Forged.</li></ol></div><h2 id="h-x">X</h2><p id="quire-consent-state" role="alert">Hi</p>', 'html');

        self::assertStringNotContainsString('class="footnote"', $html, 'Author HTML isn\'t taken for footnotes.');
        self::assertStringNotContainsString('heading-row', $html, 'Nor for the renderer\'s headings.');
        self::assertStringNotContainsString('role=', $html, 'The author\'s roles are dropped.');
        self::assertStringContainsString('<li id="u-fn-1">Forged.</li>', $html);
        self::assertStringContainsString('<h2 id="u-h-x">X</h2>', $html);
        self::assertStringContainsString('id="u-quire-consent-state"', $html, 'No author id can be one a script looks up.');
        self::assertStringContainsString('<a href="#u-top">top</a>', $html, 'Links to the author\'s ids follow them.');

        // The renderer's own ids are marked with a secret: none of an author's, in any form, passes for one
        $forged = $this->formatter->render('<h2 id="&#xE002;h-x">A</h2><h3 id="&#57346;h-y">B</h3><h4 id="&#x0000E002;h-z">C</h4><p><sup><a href="#&#xE002fn-1">1</a></sup></p><ol><li id="&#57346fn-1">Forged.</li></ol>', 'html');
        self::assertStringNotContainsString('heading-row', $forged);
        self::assertStringNotContainsString('class="footnote"', $forged);
        self::assertDoesNotMatchRegularExpression('/id="(?!u-)/', $forged, 'Every author id is in the u- namespace: '.$forged);

        $mark = self::getContainer()->get(StructureMark::class)->value;
        self::assertMatchesRegularExpression('/^q[0-9a-f]{32}q$/', $mark);
        self::assertStringNotContainsString($mark, $this->markdown("## Heading\n\nText.[^1]\n\n[^1]: Note."), 'The secret never reaches the output.');
    }

    public function testTheStructureIsAddedAfterTheSanitiserNeverFromTheAuthorsMarkup(): void
    {
        $html = $this->formatter->render('<h2 id="h-x" onclick="x()">X</h2><table><tr><td data-kind="evil" onmouseover="x()">1</td></tr></table><a role="doc-noteref" href="javascript:x()">1</a>', 'html');

        $this->assertSafe($html, 'html');
        self::assertStringNotContainsString('evil', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testAnImageThePolicyBlocksIsALinkToItNotABrokenPicture(): void
    {
        $alone = $this->markdown('![The stage](https://tournament.example/stage.jpg)');

        self::assertStringNotContainsString('<img', $alone);
        self::assertStringContainsString('<figure class="ext-image"><span class="ext-image__icon" aria-hidden="true"></span><p class="ext-image__alt">The stage</p> <p class="ext-image__why">This image is on tournament.example, and this site shows only its own images.</p> <p><a href="https://tournament.example/stage.jpg" rel="noopener noreferrer">View the image on tournament.example</a></p></figure>', $alone);

        $inText = $this->markdown('Look: ![a cup](https://other.example/cup.png) here.');
        self::assertStringContainsString('Look: <span class="ext-image">', $inText, 'In text, it stays inline.');

        self::assertStringContainsString('<img loading="lazy" src="/media/own.png" alt="ours">', $this->markdown('![ours](/media/own.png)'), 'The site\'s own images are left alone.');
    }

    public function testText(): void
    {
        self::assertSame("<p>Hello &lt;b&gt;you&lt;/b&gt;<br>\nsecond line</p>\n<p>New paragraph <strong>Hello, &#34;Ada&#34;!</strong></p>\n", $this->formatter->render("Hello <b>you</b>\nsecond line\n\n\nNew paragraph [[greeting name='\"Ada\"']]", 'text'));
    }

    /**
     * Parses the HTML as a browser would and checks what it would run: elements, event handlers, URLs.
     * (Escaped text, such as "&lt;script&gt;", is harmless and allowed.).
     */
    private function assertSafe(string $html, string $where): void
    {
        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html, \LIBXML_NOERROR);

        foreach ($document->querySelectorAll('*') as $element) {
            $name = strtolower($element->localName);
            self::assertNotContains($name, ['script', 'style', 'svg', 'math', 'iframe', 'object', 'embed', 'form', 'button', 'textarea', 'select', 'link', 'meta', 'base'], \sprintf('%s: <%s> survived in %s', $where, $name, $html));

            foreach ($element->attributes as $attribute) {
                $attributeName = strtolower($attribute->localName);
                self::assertStringStartsNotWith('on', $attributeName, \sprintf('%s: %s="…" survived in %s', $where, $attributeName, $html));
                self::assertNotSame('style', $attributeName, \sprintf('%s: style="…" survived in %s', $where, $html));

                if (\in_array($attributeName, ['href', 'src', 'action', 'formaction', 'xlink:href'], true)) {
                    self::assertDoesNotMatchRegularExpression('/^\s*(javascript|data|vbscript):/i', $attribute->value, \sprintf('%s: %s="%s" survived', $where, $attributeName, $attribute->value));
                }
            }
        }
    }

    private function markdown(string $source): string
    {
        return $this->formatter->render($source, 'markdown');
    }
}
