<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Quire\Head\Head;
use Quire\Head\HeadContributorInterface;
use Quire\Head\Internal\HeadRenderer;

/**
 * A page's head tags: one per key, in order, escaped; and contributors by priority.
 */
final class HeadTest extends TestCase
{
    public function testATagSetAgainReplacesTheOneBeforeWhereItWas(): void
    {
        $head = new Head()
            ->meta('description', 'First')
            ->link('canonical', 'https://example.com/a')
            ->meta('description', 'Second');

        self::assertSame(['meta:description', 'link:canonical'], $head->keys());
        self::assertSame("<meta name=\"description\" content=\"Second\">\n<link rel=\"canonical\" href=\"https://example.com/a\">\n", $head->toHtml());
    }

    public function testEmptyValuesRemoveTheirTag(): void
    {
        $head = new Head()
            ->meta('description', 'Text')
            ->property('og:image', 'https://example.com/a.png')
            ->link('canonical', 'https://example.com/a')
            ->jsonLd('site', ['@type' => 'WebSite']);

        $head->meta('description', '')->property('og:image', null)->link('canonical', null)->remove('json-ld:site');

        self::assertSame([], $head->keys());
        self::assertSame('', $head->toHtml());
    }

    public function testLinksOfTheSameRelNeedTheirOwnKeys(): void
    {
        $head = new Head()
            ->link('alternate', 'https://example.com/de/', ['hreflang' => 'de'], key: 'alternate:de')
            ->link('alternate', 'https://example.com/fr/', ['hreflang' => 'fr'], key: 'alternate:fr');

        self::assertSame(['link:alternate:de', 'link:alternate:fr'], $head->keys());
        self::assertSame(['rel' => 'alternate', 'href' => 'https://example.com/fr/', 'hreflang' => 'fr'], $head->get('link:alternate:fr'));
        self::assertStringContainsString('<link rel="alternate" href="https://example.com/de/" hreflang="de">', $head->toHtml());
    }

    public function testValuesAreEscaped(): void
    {
        $headline = '</script><script>alert(1)</script>';
        $html = new Head()
            ->meta('description', '"><script>alert(1)</script>')
            ->jsonLd('item', ['headline' => $headline])
            ->toHtml();

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('content="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        self::assertSame(1, preg_match('~<script type="application/ld\+json">(.*)</script>~', $html, $match));
        self::assertSame(['headline' => $headline], json_decode($match[1], true), 'The data is intact once read.');
    }

    public function testContributorsRunLowestPriorityFirstSoLaterOnesWin(): void
    {
        $contributor = static fn (string $value): HeadContributorInterface => new readonly class($value) implements HeadContributorInterface {
            public function __construct(
                private string $value,
            ) {
            }

            public function contribute(Head $head, array $context): void
            {
                $head->meta('description', $this->value.(\is_string($context['suffix'] ?? null) ? $context['suffix'] : ''));
            }
        };
        $services = ['late' => $contributor('Late'), 'base' => $contributor('Base'), 'same' => $contributor('Same')];
        $locator = new readonly class($services) implements ContainerInterface {
            /** @param array<string, HeadContributorInterface> $services */
            public function __construct(
                private array $services,
            ) {
            }

            public function get(string $id): HeadContributorInterface
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };

        $registered = [
            ['class' => 'late', 'priority' => 10, 'owner' => 'acme'],
            ['class' => 'base', 'priority' => -100, 'owner' => 'core'],
            ['class' => 'same', 'priority' => 10, 'owner' => 'site'],
        ];

        $head = new HeadRenderer($locator, $registered)->build(['suffix' => '!']);

        self::assertSame(['name' => 'description', 'content' => 'Same!'], $head->get('meta:description'), 'The same priority keeps the order of registration.');
    }
}
