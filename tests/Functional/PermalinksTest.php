<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Event\ContentViewing;
use Quire\Content\Permalink\Redirects;
use Quire\Content\Status;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Quire\Testing\Database\QueryLog;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Content at the URLs the permalink settings define, the homepage, listings and redirects.
 */
final class PermalinksTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    private ContentWriter $writer;

    private Settings $settings;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(ContentWriter::class);
        $this->settings = self::getContainer()->get(Settings::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testAPublishedItemRendersAtItsUrl(): void
    {
        $this->publish('post', 'Hello world', body: 'My *first* post.');

        $this->client->request('GET', '/blog/hello-world');
        // Measured on a second request, when nothing is loaded yet, as on a real one.
        $queries = $this->queriesFor('/blog/hello-world');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Hello world');
        self::assertSelectorTextSame('article em', 'first');
        self::assertSelectorExists('link[rel=canonical][href="http://localhost/blog/hello-world"]');
        self::assertCount(2, $queries, "One query for the settings, one for the item:\n".implode("\n", $queries));
    }

    public function testVisitorsOnlySeePublishedItems(): void
    {
        $this->writer->create(new CreateContent('post', 'Draft'));
        $scheduled = $this->writer->create(new CreateContent('post', 'Soon'));
        $this->writer->transition($scheduled->id, $scheduled->version, Status::SCHEDULE, new \DateTimeImmutable('+1 day'));
        $trashed = $this->publish('post', 'Gone');
        $this->writer->trash($trashed->id, $trashed->version);

        foreach (['/blog/draft', '/blog/soon', '/blog/gone', '/blog/nothing-here', '/nothing/at/all'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testOtherSpellingsRedirectToTheCanonicalUrl(): void
    {
        $this->publish('post', 'Hello world');

        $this->assertRedirects('/blog/hello-world/', '/blog/hello-world');
        $this->assertRedirects('/Blog/Hello-World', '/blog/hello-world');
        $this->assertRedirects('/blog/hello-world/?utm_source=feed', '/blog/hello-world?utm_source=feed');
    }

    public function testPagesNestUnderTheirParents(): void
    {
        $about = $this->publish('page', 'About');
        $this->publish('page', 'Team', parent: $about);

        $this->client->request('GET', '/about/team');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Team');

        $this->client->request('GET', '/team');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEarlierUrlsRedirectToTheCurrentOneWithoutChains(): void
    {
        $post = $this->publish('post', 'Hello');
        $post = $this->writer->update(new UpdateContent($post->id, $post->version)->slug('hi'));
        $this->writer->update(new UpdateContent($post->id, $post->version)->slug('hey'));

        $this->assertRedirects('/blog/hello', '/blog/hey');
        $this->assertRedirects('/blog/hi', '/blog/hey');

        $about = $this->publish('page', 'About');
        $this->publish('page', 'Team', parent: $about);
        $this->writer->update(new UpdateContent($about->id, $about->version)->slug('company'));

        $this->assertRedirects('/about/team', '/company/team', 'Descendants keep their earlier URLs too.');
    }

    public function testChangingThePatternKeepsOldUrlsWorking(): void
    {
        $post = $this->publish('post', 'Hello', publishedAt: '2025-03-04 10:00');

        $this->settings->set('quire.permalinks.post', '/{year}/{month}/{slug}');

        $this->client->request('GET', '/2025/03/hello');
        self::assertResponseIsSuccessful();
        $this->assertRedirects('/blog/hello', '/2025/03/hello', 'The previous pattern redirects.');
        $this->assertRedirects('/2024/01/hello', '/2025/03/hello', 'A wrong date redirects to the right one.');

        $this->writer->update(new UpdateContent($post->id, $post->version)->slug('hi'));
        $this->assertRedirects('/blog/hello', '/2025/03/hi', 'An earlier path in an earlier pattern.');
    }

    public function testTheHomepageListsPostsByDefault(): void
    {
        $this->settings->set('quire.content.per_page', 2);
        $this->publish('post', 'Oldest', publishedAt: '-3 days');
        $this->publish('post', 'Middle', publishedAt: '-2 days');
        $this->publish('post', 'Newest', publishedAt: '-1 day');

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSame(['Newest', 'Middle'], $this->client->getCrawler()->filter('article h2')->each(static fn ($h2): string => $h2->text()));
        self::assertSelectorExists('a[rel=next][href="/?page=2"]');

        $this->client->request('GET', '/?page=2');
        self::assertSame(['Oldest'], $this->client->getCrawler()->filter('article h2')->each(static fn ($h2): string => $h2->text()));

        $this->assertRedirects('/blog', '/', 'The list has one URL: the homepage.');
        $this->assertRedirects('/blog?page=2', '/?page=2');
        $this->assertRedirects('/?page=1', '/');

        foreach (['/?page=3', '/?page=0', '/?page=two'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testTheHomepageCanShowAPage(): void
    {
        $this->publish('post', 'A post');
        $this->publish('page', 'Welcome', body: 'Hi there.');
        $this->settings->set('site.homepage_page', 'welcome');
        $this->settings->set('site.homepage', 'page');

        $this->client->request('GET', '/');
        self::assertSelectorTextSame('h1', 'Welcome');
        $this->assertRedirects('/welcome', '/', 'The page has one URL: the homepage.');

        $this->client->request('GET', '/blog');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('main h1', 'Posts');
        self::assertSelectorTextSame('article h2 a[href="/blog/a-post"]', 'A post');
    }

    public function testTheHomepageCanShowAnExtensionsRoute(): void
    {
        $this->settings->set('site.homepage_route', 'fixture_hello');
        $this->settings->set('site.homepage', 'route');

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'world');
    }

    public function testAMisconfiguredHomepageStillResponds(): void
    {
        $this->settings->set('site.homepage', 'page');

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Hello Quire');
    }

    public function testRedirectsAddedByHand(): void
    {
        $post = $this->publish('post', 'Hello');
        $redirects = self::getContainer()->get(Redirects::class);
        $redirects->add('/2019/05/hello-world/', $post);
        $redirects->add('https://old.example.com/Shop/', 'https://shop.example.com', 302);
        $redirects->add('/about-us', '/about');

        $this->assertRedirects('/2019/05/hello-world', '/blog/hello');
        $this->writer->update(new UpdateContent($post->id, $post->version)->slug('hi'));
        $this->assertRedirects('/2019/05/hello-world', '/blog/hi', 'The redirect follows the item.');
        $this->assertRedirects('/shop', 'https://shop.example.com', status: 302);
        $this->assertRedirects('/about-us', '/about');

        $this->publish('page', 'About us');
        $this->client->request('GET', '/about-us');
        self::assertResponseIsSuccessful('Content takes precedence over redirects.');
    }

    public function testListenersCanDenyAView(): void
    {
        $this->publish('post', 'Secret');
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(ContentViewing::class, static function (ContentViewing $event): void {
            if ('secret' === $event->content->slug) {
                $event->deny();
            }
        });

        $this->client->request('GET', '/blog/secret');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOtherRoutesTakePrecedence(): void
    {
        $this->client->request('GET', '/_quire/health');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testPatternsAndListsAreCheckedWhenSaved(): void
    {
        $this->assertSettingFails('quire.permalinks.post', '/{path}', 'only hierarchical types');
        $this->assertSettingFails('quire.permalinks.post', '/blog/{title}', 'unknown placeholder {title}');
        $this->assertSettingFails('quire.permalinks.greeting_card', '/blog/{slug}', 'competes for the same URLs as the posts ("/blog/{slug}")');
        $this->assertSettingFails('quire.listings.greeting_card', '/blog', 'already the list of posts');
        $this->assertSettingFails('quire.listings.post', '/', 'must start with "/"');
        $this->assertSettingFails('site.homepage_listing', 'page', 'isn\'t a content type with a list');
        $this->assertSettingFails('site.homepage_page', 'missing', 'No page with the ID or path "missing"');
        $this->assertSettingFails('site.homepage_route', 'quire_content', 'not one of Quire\'s content routes');
        $this->assertSettingFails('site.homepage_route', 'fixture_nope', 'The route "fixture_nope" doesn\'t exist');

        $this->settings->set('quire.permalinks.greeting_card', '/blog/cards/{slug}');
        self::assertSame('/blog/cards/{slug}', $this->settings->get('quire.permalinks.greeting_card'));
    }

    private function publish(string $type, string $title, string $body = '', ?Content $parent = null, string $publishedAt = '-1 hour'): Content
    {
        $item = $this->writer->create(new CreateContent($type, $title, body: $body, parentId: $parent?->id));

        return $this->writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable($publishedAt));
    }

    private function assertRedirects(string $from, string $to, string $message = '', int $status = 301): void
    {
        $this->client->request('GET', $from);
        self::assertResponseStatusCodeSame($status, $from.' '.$message);
        self::assertSame($to, $this->client->getResponse()->headers->get('Location'), $message);
    }

    private function assertSettingFails(string $key, mixed $value, string $message): void
    {
        try {
            $this->settings->set($key, $value);
            self::fail(\sprintf('Setting %s to %s should fail.', $key, json_encode($value)));
        } catch (InvalidSettingValueException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function queriesFor(string $uri): array
    {
        // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only)
        $log = self::getContainer()->get(QueryLog::class);
        \assert($log instanceof QueryLog);
        $log->start();
        $this->client->request('GET', $uri);

        return $log->stop();
    }
}
