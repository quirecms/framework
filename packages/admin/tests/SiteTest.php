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

    public function testTheSettingsReadAsADocument(): void
    {
        $this->signIn('admin');
        $this->settings()->set('site.title', 'Tournament News');
        $this->settings()->set('quire.content.changes_need_approval', true);
        $crawler = $this->client->request('GET', '/admin/settings');

        self::assertSelectorTextSame('h1', 'Settings');
        self::assertSame('page', $crawler->filter('.section-switch a')->first()->attr('aria-current'));
        $site = $crawler->filter('.settings-doc__part')->first();
        $text = $this->readable($site->filter('.settings-doc__text'));
        self::assertSame('This site is called Tournament News. It\'s described as not set. It\'s written in English. It\'s in the UTC time zone. Its homepage shows a list of content. The homepage\'s list is of post. When a page without an image of its own is shared, it shows not set.', $text, 'In the order it reads best; the homepage\'s page only when it shows one.');
        self::assertStringStartsWith('Changes to live content need a reviewer\'s approval before they go live. Checks of content before it\'s published: each at its default level.', $this->readable($crawler->filter('.settings-doc__text')->eq(1)));
        self::assertCount(2, $crawler->filter('.settings-doc__value--changed'), 'What differs from the defaults stands out.');
        self::assertSame(['Site title: Quire → Tournament News', 'Approve changes: off → on'], $crawler->filter('.settings-changed div')->each(fn (Crawler $d): string => $d->filter('dt')->text().': '.$this->readable($d->filter('dd'))));

        // Each value opens its own small form, and comes back to the document.
        $edit = $crawler->filter('#edit-setting-site-title');
        self::assertSame('edit-setting-site-title', $crawler->filter('button[popovertarget="edit-setting-site-title"]')->attr('popovertarget'));
        $this->client->submit($edit->selectButton('Save')->form(['settings[site.title]' => 'Spring Cup']));
        self::assertResponseRedirects('/admin/settings', 303);
        self::assertSame('Spring Cup', $this->settings()->get('site.title'));
        self::assertTrue($this->settings()->get('quire.content.changes_need_approval'), 'Only that setting.');

        // Into a file and back, after looking at every difference.
        $this->client->request('GET', '/admin/settings/export');
        $file = (string) $this->client->getResponse()->getContent();
        self::assertSame("quire.content.changes_need_approval: true\nsite.title: 'Spring Cup'\n", $file);
        $this->settings()->set('site.title', 'Other');
        $crawler = $this->client->request('GET', '/admin/settings');
        $this->client->request('POST', '/admin/settings/import', ['_token' => (string) $crawler->filter('.settings-move__import input[name=_token]')->attr('value'), 'content' => $file."acme.unknown: 1\n"]);
        self::assertSelectorTextSame('caption', '1 setting would change');
        self::assertSame(['Site title site.title', 'Other', 'Spring Cup'], $this->client->getCrawler()->filter('tbody tr')->first()->filter('th, td')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSelectorTextContains('.q-help', 'acme.unknown');
        self::assertSame('Other', $this->settings()->get('site.title'), 'Not yet.');
        $this->client->submit($this->client->getCrawler()->selectButton('Change them')->form());
        self::assertSame('Spring Cup', $this->settings()->get('site.title'));

        // Back to the defaults, all at once.
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', '1 setting changed.');
        $this->client->submit($crawler->selectButton('Back to the defaults…')->form());
        self::assertSame(['Quire', false], [$this->settings()->get('site.title'), $this->settings()->get('quire.content.changes_need_approval')]);
    }

    public function testSettingsAreChangedAGroupAtATime(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/settings/site');

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
        self::assertSame('Quire\'s built-in templates in use', $crawler->filter('.look__title')->last()->text());
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
        self::assertSame('Enabled', $crawler->filter('.extension-card:contains("quire-admin") .q-tag')->text());
        self::assertStringContainsString('admin pages', $crawler->filter('.extension-card:contains("quire-admin") .extension-card__adds')->text(), 'What it adds.');

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

    /**
     * The text as it's seen: without what's only for screen readers.
     */
    private function readable(Crawler $node): string
    {
        $element = $node->getNode(0)?->cloneNode(true);
        \assert($element instanceof \DOMElement);

        $hidden = new \DOMXPath($element->ownerDocument ?? new \DOMDocument())->query('.//*[contains(concat(" ", @class, " "), " q-sr ")]', $element);

        foreach (false === $hidden ? [] : iterator_to_array($hidden) as $node) {
            if ($node instanceof \DOMNode) {
                $node->parentNode?->removeChild($node);
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $element->textContent));
    }
}
