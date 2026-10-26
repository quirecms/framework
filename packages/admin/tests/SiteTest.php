<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Check\ContentChecker;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\ExtensionPurgerInterface;
use Quire\Extension\ExtensionSwitcher;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\Rebuild\AppRebuilderInterface;
use Quire\Extension\State\ExtensionStateStoreInterface;
use Quire\Testing\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Site management in the admin: settings, themes and extensions, for administrators only.
 */
final class SiteTest extends AdminTestCase
{
    public function testOnlyAdministratorsManageTheSite(): void
    {
        $this->signIn('editor');

        foreach (['/admin/settings', '/admin/themes', '/admin/extensions', '/admin/extensions/quire-admin/disable'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }

        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(0, $crawler->filter('nav a[href="/admin/settings"]'), 'Not in the menu either.');
    }

    public function testSettingsAreChangedAGroupAtATime(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertSame(['Site', 'Content', 'Addresses', 'Users', 'Storage'], $crawler->filter('.q-views a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('page', $crawler->filter('.q-views a')->first()->attr('aria-current'));
        self::assertSame('The language of the site and of new content, as a language tag such as "en", "de" or "pt-BR".', $crawler->filter('#setting-site-locale-help')->text());
        self::assertSame('setting-site-locale-help', $crawler->filter('#setting-site-locale')->attr('aria-describedby'));
        self::assertCount(0, $crawler->filter('[name="settings[theme.active]"], [name="settings[quire.permissions.grants]"]'), 'Those have pages of their own.');

        $this->service(ContentWriter::class)->create(new CreateContent('page', 'Home'));
        $this->client->submit($crawler->selectButton('Save settings')->form(['settings[site.title]' => 'My site', 'settings[site.homepage]' => 'page', 'settings[site.homepage_page]' => 'home']));
        self::assertResponseRedirects('/admin/settings/site', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', '3 settings saved.');
        self::assertSame('My site', $this->settings()->get('site.title'));
        self::assertSame('home', $this->settings()->get('site.homepage_page'));

        // Emptied, a setting that can be empty saves as null.
        $this->client->submit($crawler->selectButton('Save settings')->form(['settings[site.homepage_page]' => '']));
        self::assertNull($this->settings()->get('site.homepage_page'));

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Back to the default (Site title)')->form());
        self::assertSame('Quire', $this->settings()->get('site.title'));
        self::assertSame('database', $this->settings()->source('site.homepage'), 'Only that one.');
    }

    public function testInvalidValuesAreShownWithTheProblem(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/settings/content');

        $crawler = $this->client->submit($crawler->selectButton('Save settings')->form(['settings[quire.content.per_page]' => 'ten', 'settings[quire.content.revisions_keep]' => '20']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('#setting-quire-content-per_page', $crawler->filter('.q-error-summary a')->attr('href'));
        self::assertSame('ten', $crawler->filter('#setting-quire-content-per_page')->attr('value'), 'What was entered, to correct.');
        self::assertSame('true', $crawler->filter('#setting-quire-content-per_page')->attr('aria-invalid'));
        self::assertSame(20, $this->settings()->get('quire.content.revisions_keep'), 'Valid ones are saved.');
        self::assertSame(10, $this->settings()->get('quire.content.per_page'));
    }

    public function testCheckLevelsAreChosenPerCheck(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/settings/content');

        self::assertSame('Default (Warning)', $crawler->filter('select[name="settings[quire.content.check_levels][core.alt-text]"] option:selected')->text());
        $this->client->submit($crawler->selectButton('Save settings')->form([
            'settings[quire.content.check_levels][core.alt-text]' => 'error',
            'settings[quire.content.check_levels][core.excerpt]' => 'off',
        ]));

        self::assertEqualsCanonicalizing(['core.alt-text' => 'error', 'core.excerpt' => 'off'], $this->settings()->get(ContentChecker::LEVELS_SETTING));

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Save settings')->form());
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Nothing changed.');
    }

    public function testThemesCantChangeWhenPinned(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/themes');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The theme is pinned in configuration', $crawler->filter('.q-notice--warning')->text());
        self::assertSame('Quire\'s built-in templates in use', $crawler->filter('.q-card__title')->last()->text());
        self::assertCount(0, $crawler->filter('form[action="/admin/themes"]'));

        // The settings' form has the token the site's pages share.
        $token = (string) $this->client->request('GET', '/admin/settings')->filter('main input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/themes', ['_token' => $token, 'theme' => 'quire-theme-default']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.q-notice--error', 'pinned');
    }

    public function testExtensionsAreEnabledAndDisabledAfterConfirming(): void
    {
        [$state, $rebuilds] = $this->unpinExtensions(['quire-admin']);
        $this->signIn('admin');

        $crawler = $this->client->request('GET', '/admin/extensions');
        self::assertSame('Enabled', $crawler->filter('tr:contains("quire-admin") .q-tag')->text());

        $crawler = $this->client->click($crawler->selectLink('Disable Admin')->link());
        self::assertSelectorTextSame('h1', 'Disable “Admin”?');
        self::assertSelectorTextContains('.q-notice--warning', 'This turns off the admin interface');

        $this->client->submit($crawler->selectButton('Disable')->form());
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Disabled: quire-admin.');
        self::assertSame([], $state->enabledIds());
        self::assertSame([false], $rebuilds->calls);

        $crawler = $this->client->request('GET', '/admin/extensions/quire-admin/enable');
        $this->client->submit($crawler->selectButton('Enable')->form());
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Enabled: quire-admin.');
        self::assertSame(['quire-admin'], $state->enabledIds());
        self::assertSame([false, true], $rebuilds->calls, 'With migrations.');
    }

    public function testPurgingTakesTheIdTyped(): void
    {
        [$state, , $purges] = $this->unpinExtensions(['quire-admin']);
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/extensions/quire-admin/purge');

        $crawler = $this->client->submit($crawler->selectButton('Disable and delete the data')->form(['confirm' => 'admin']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#confirm-error', 'Type the extension\'s ID to confirm that its data is deleted.');
        self::assertSame([], $purges->purged);

        $this->client->submit($crawler->selectButton('Disable and delete the data')->form(['confirm' => 'quire-admin']));
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Disabled, and their data deleted: quire-admin.');
        self::assertSame(['quire-admin'], $purges->purged);
        self::assertSame([], $state->enabledIds());
    }

    public function testExtensionsCantChangeWhenPinned(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/extensions');

        self::assertStringContainsString('pinned', $crawler->filter('.q-notice--warning')->text());
        self::assertCount(0, $crawler->filter('.content-list__actions a'));

        $this->client->request('GET', '/admin/extensions/quire-admin/disable');
        self::assertCount(0, $this->client->getCrawler()->filter('form button[type=submit]:contains("Disable")'));
    }

    /**
     * Extensions as on a site where they aren't pinned, with rebuilds and purges recorded instead of done.
     *
     * @param list<string> $enabled
     *
     * @return array{ExtensionStateStoreInterface, object{calls: list<bool>}, object{purged: list<string>}}
     */
    private function unpinExtensions(array $enabled): array
    {
        $state = new class($enabled) implements ExtensionStateStoreInterface {
            /** @param list<string> $ids */
            public function __construct(private array $ids)
            {
            }

            public function enabledIds(): array
            {
                return $this->ids;
            }

            public function readOnlyReason(): ?string
            {
                return null;
            }

            public function enable(array $ids): void
            {
                $this->ids = array_values(array_unique([...$this->ids, ...$ids]));
            }

            public function disable(array $ids): void
            {
                $this->ids = array_values(array_diff($this->ids, $ids));
            }
        };
        $rebuilds = new class implements AppRebuilderInterface {
            /** @var list<bool> */
            public array $calls = [];

            public function rebuild(bool $migrate, \Closure $output): void
            {
                $this->calls[] = $migrate;
            }
        };
        $purges = new class implements ExtensionPurgerInterface {
            /** @var list<string> */
            public array $purged = [];

            public function purge(array $extensions, \Closure $output): void
            {
                array_push($this->purged, ...array_map(static fn (ExtensionManifest $m): string => $m->id, $extensions));
            }
        };

        $container = self::getContainer();
        $manager = new ExtensionManager($this->service(InstalledExtensions::class), $state, $this->service(EventDispatcherInterface::class));
        $container->set(ExtensionManager::class, $manager);
        $container->set(ExtensionSwitcher::class, new ExtensionSwitcher($manager, $purges, $rebuilds));

        return [$state, $rebuilds, $purges];
    }
}
