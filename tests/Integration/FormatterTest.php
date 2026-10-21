<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Quire\Fixtures\Hello\Formatter\GreetingShortcode;
use Quire\Formatter\Exception\UnknownFormatException;
use Quire\Formatter\Formatter;
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

        self::assertStringContainsString('<h2 id="getting-started">Getting started</h2>', $html);
        self::assertStringContainsString('<input checked disabled type="checkbox" /> Done', $html);
        self::assertStringContainsString('<del>old</del>', $html);
        self::assertStringContainsString('<td>1</td>', $html);
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
        self::assertStringContainsString('<p>[[unknown x&#61;1]]</p>', $html, 'Unknown shortcodes stay as written.');
        self::assertStringContainsString("<pre><code>[[greeting]]\n</code></pre>", $html, 'Literal in code blocks.');
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
        self::assertStringContainsString('<img loading="lazy" src="/a.png" alt="a" />', $content, 'This post-processor only runs for "content".');
        self::assertStringContainsString('The note.', $content);
        self::assertStringContainsString('role="doc-endnotes"', $content, 'The footnote extension only runs for "content".');

        $review = $this->formatter->render(':wave: [[greeting]] [[embed id=1]] Note[^1]', 'markdown', 'review');
        self::assertSame('<p>👋 <strong>Hello, world!</strong> [[embed id&#61;1]] Note[^1]</p>', trim($review));
    }

    public function testText(): void
    {
        self::assertSame("<p>Hello &lt;b&gt;you&lt;/b&gt;<br />\nsecond line</p>\n<p>New paragraph <strong>Hello, &#34;Ada&#34;!</strong></p>\n", $this->formatter->render("Hello <b>you</b>\nsecond line\n\n\nNew paragraph [[greeting name='\"Ada\"']]", 'text'));
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
