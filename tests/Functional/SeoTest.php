<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Theme\Event\SeoTagsBuilding;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

/**
 * quire_head() on Quire's built-in templates: the SEO tags, and the tags extensions add.
 */
final class SeoTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testPagesWithoutContentDescribeTheSite(): void
    {
        $crawler = $this->client->request('GET', '/cards');

        self::assertSame('Greeting cards', $crawler->filter('head meta[property="og:title"]')->attr('content'));
        self::assertSame('website', $crawler->filter('head meta[property="og:type"]')->attr('content'));
        self::assertCount(0, $crawler->filter('head script[type="application/ld+json"]'), 'Structured data describes the homepage and posts only.');
    }

    public function testExtensionsCanChangeTheTags(): void
    {
        $this->publish('Hello world');
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(SeoTagsBuilding::class, static function (SeoTagsBuilding $event): void {
            $event->tags->imageUrl = 'https://example.com/'.$event->content?->slug.'.png';
            $event->tags->meta['robots'] = 'noindex';
        });

        $crawler = $this->client->request('GET', '/blog/hello-world');

        self::assertSame('https://example.com/hello-world.png', $crawler->filter('head meta[property="og:image"]')->attr('content'));
        self::assertSame('summary_large_image', $crawler->filter('head meta[name="twitter:card"]')->attr('content'));
        self::assertSame('noindex', $crawler->filter('head meta[name=robots]')->attr('content'));
    }

    public function testStructuredDataCantBreakOutOfItsScript(): void
    {
        $this->publish('Hello </script><script>alert(1)</script>');

        $this->client->request('GET', '/blog/hello-script-script-alert-1-script');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('"headline":"Hello \u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E"', $html);
    }

    public function testEveryTagIsOnThePageOnceWithTheExtensionsAfterQuires(): void
    {
        $this->publish('Hello world');

        $crawler = $this->client->request('GET', '/blog/hello-world');

        self::assertCount(1, $crawler->filter('head meta[name=description]'));
        self::assertCount(1, $crawler->filter('head link[rel=canonical]'));
        self::assertSame('quire-fixture-hello', $crawler->filter('head meta[name=google-site-verification]')->attr('content'), 'The fixture extension\'s head contributor.');
        $names = $crawler->filter('head meta[name], head meta[property]')->each(static fn ($meta): string => (string) ($meta->attr('name') ?? $meta->attr('property')));
        self::assertCount(\count(array_unique($names)), $names, 'No tag twice: '.implode(', ', $names));
        self::assertSame('google-site-verification', array_last($names), 'Contributors with the default priority come after Quire\'s own tags.');
    }

    public function testTheOldNameStillPrintsTheHead(): void
    {
        $html = self::getContainer()->get(Environment::class)->createTemplate('{{ quire_seo() }}')->render(['canonical_url' => 'https://example.com/cards']);

        self::assertStringContainsString('<link rel="canonical" href="https://example.com/cards">', $html);
        self::assertStringContainsString('<meta name="google-site-verification" content="quire-fixture-hello">', $html);
    }

    private function publish(string $title): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $item = $writer->create(new CreateContent('post', $title, body: 'Text.'));
        $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));
    }
}
