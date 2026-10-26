<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Permission\Permissions;
use Quire\Routing\ExtensionRouteLoader;
use Quire\User\CreateUser;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The admin interface as other extensions see it: pages in the route group "admin", for users with the
 * ability "quire.admin.access", with the admin's assets, menu and dashboard.
 */
final class AdminGroupTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $users = self::getContainer()->get(UserWriter::class);

        foreach (['editor' => ['editor'], 'member' => ['member'], 'moderator' => ['moderator']] as $name => $roles) {
            $users->create(new CreateUser(ucfirst($name), $name.'@example.com', password: 'correct-horse-42', roles: $roles));
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testOnlyUsersWhoMayUseTheAdminOpenItsPages(): void
    {
        $this->client->request('GET', '/admin/greetings');
        self::assertResponseRedirects('/login', message: 'Guests sign in first.');

        $this->signIn('member');
        $this->client->request('GET', '/admin/greetings');
        self::assertResponseStatusCodeSame(403);

        $this->signIn('moderator');
        $this->client->request('GET', '/admin/greetings');
        self::assertResponseStatusCodeSame(403, 'Moderating greetings isn\'t using the admin.');

        $this->signIn('editor');
        $this->client->request('GET', '/admin/greetings');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('main h1', 'Greetings');
        self::assertSelectorTextSame('.q-rail a[aria-current=page]', 'Greetings', 'Its menu entry is current; alone in its section, it\'s named after it.');
        self::assertSelectorExists('meta[name=robots][content="noindex, nofollow"]');
    }

    public function testAdminPagesHaveTheAdminsAssetsAndTheSiteDoesnt(): void
    {
        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin');
        $stylesheets = $crawler->filter('head link[rel=stylesheet]')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertCount(6, $stylesheets);
        self::assertStringStartsWith('/assets/quire-admin/quire-ui-', $stylesheets[0], 'Quire UI first, then the admin\'s own pages.');
        self::assertStringStartsWith('/assets/quire-admin/admin-', $stylesheets[1]);
        self::assertStringStartsWith('/assets/quire-fixture-hello/admin-', $stylesheets[2], 'Other extensions\' admin stylesheets come after the admin\'s.');
        self::assertCount(3, array_filter($stylesheets, static fn (string $href): bool => str_contains($href, 'open-props')), 'Open Props\' scales, from the importmap.');
        self::assertStringContainsString("import 'quire-admin/admin.js'", $crawler->filter('head')->html());
        // The importmap maps every module the site knows; only the admin's are loaded.
        self::assertSame(["import 'quire-admin/admin.js';"], $crawler->filter('head script[type=module]')->each(static fn ($script): string => $script->text()));
        $preloads = $crawler->filter('head link[rel=modulepreload]')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertSame([], array_values(array_filter($preloads, static fn (string $href): bool => !str_starts_with($href, '/assets/quire-admin/') && !str_starts_with($href, '/assets/vendor/@hotwired/'))), 'Not the site\'s modules.');
        self::assertCount(2, array_filter($preloads, static fn (string $href): bool => str_starts_with($href, '/assets/vendor/@hotwired/')), 'The packages the admin imports, Turbo and Stimulus.');

        $crawler = $this->client->request('GET', '/');
        self::assertSame([], array_filter($crawler->filter('head link[rel=stylesheet], head link[rel=modulepreload]')->each(static fn ($link): string => (string) $link->attr('href')), static fn (string $href): bool => str_contains($href, 'admin')), 'The site loads none of the admin\'s files.');
        self::assertStringNotContainsString('admin', implode('', $crawler->filter('head script[type=module]')->each(static fn ($script): string => $script->text())));
    }

    public function testMenuEntriesAndWidgetsFollowAbilities(): void
    {
        $this->signIn('editor');
        $this->client->request('GET', '/admin');
        self::assertSelectorTextSame('.q-rail a[aria-current=page]', 'Home');
        self::assertSame(['Home', 'Content', 'Greetings'], $this->client->getCrawler()->filter('.q-rail__item')->each(static fn ($item): string => $item->text()), 'The sections the editor may open, in order.');
        self::assertSelectorExists('.q-rail a[href="/admin/greetings"]');
        self::assertSelectorTextSame('.widgets .q-card:first-child h2', 'Greetings', 'Ordered: the widget asked for position 5.');

        // Members who may use the admin, but not moderate greetings.
        self::getContainer()->get(Permissions::class)->grant('member', ['quire.admin.access']);
        $this->signIn('member');
        $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.admin-nav a[href="/admin/greetings"]');
        self::assertSelectorNotExists('.fixture-greetings');
        self::assertSelectorNotExists('.admin-nav__heading', 'No empty sections.');
    }

    public function testTheAdminsPathCanChange(): void
    {
        $loader = new ExtensionRouteLoader([
            ['type' => 'route', 'extension' => 'acme', 'group' => 'admin', 'name' => 'acme_admin_home', 'path' => '/', 'controller' => 'Acme\Controller', 'methods' => [], 'requirements' => [], 'defaults' => []],
            ['type' => 'route', 'extension' => 'acme', 'group' => 'admin', 'name' => 'acme_admin_reviews', 'path' => '/reviews', 'controller' => 'Acme\Controller', 'methods' => [], 'requirements' => [], 'defaults' => []],
            ['type' => 'route', 'extension' => 'acme', 'group' => 'site', 'name' => 'acme_reviews', 'path' => '/reviews', 'controller' => 'Acme\Controller', 'methods' => [], 'requirements' => [], 'defaults' => []],
        ], [], '/cms-admin');

        $routes = $loader->load('.', ExtensionRouteLoader::TYPE);
        $path = static fn (string $name): ?string => $routes->get($name)?->getPath();
        $group = static fn (string $name): mixed => $routes->get($name)?->getDefault(ExtensionRouteLoader::GROUP);

        self::assertSame('/cms-admin', $path('acme_admin_home'));
        self::assertSame('/cms-admin/reviews', $path('acme_admin_reviews'));
        self::assertSame('admin', $group('acme_admin_reviews'));
        self::assertSame('/reviews', $path('acme_reviews'));
        self::assertSame('site', $group('acme_reviews'));
    }

    private function signIn(string $username): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => $username, '_password' => 'correct-horse-42']);
        self::assertResponseRedirects();
    }
}
