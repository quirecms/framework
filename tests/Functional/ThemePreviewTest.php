<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Quire\Extension\ExtensionContributions;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;
use Quire\Theme\ThemePreview;
use Quire\User\Role;

/**
 * Previewing an installed theme before using it, and what each extension and theme adds to the site.
 */
final class ThemePreviewTest extends ExtensionTestCase
{
    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    #[\Override]
    protected static array $extensions = ['quire-admin', 'quire-fixture-hello'];

    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testAnAdminSeesTheSiteInAnotherThemeAndNobodyElseDoes(): void
    {
        $this->content('post', 'Spring draw')->body('Sixteen teams.')->published()->create();
        $preview = $this->service(ThemePreview::class);
        self::assertNull($preview->reason('quire-fixture-default-child'));

        $this->signIn('admin');
        $this->client->request('POST', '/_quire/theme-preview', ['_token' => $this->csrfToken('quire_theme_preview'), 'theme' => 'quire-fixture-default-child', 'back' => '/blog/spring-draw']);
        self::assertResponseRedirects('/blog/spring-draw', 303);

        $crawler = $this->client->request('GET', '/blog/spring-draw');
        self::assertSelectorTextSame('.child-banner', 'Read by the child theme', 'Its templates, and its parent\'s for the rest.');
        self::assertSelectorTextContains('.quire-theme-preview', 'You’re previewing the theme “quire-fixture-default-child”: only you see it.');
        self::assertCount(1, $crawler->filter('link[rel=stylesheet][href*="quire-theme-default/style"]'), 'The stylesheets of the themes it inherits from.');

        // Not the admin's pages.
        $this->client->request('GET', '/admin');
        self::assertSelectorNotExists('.quire-theme-preview');

        // Stopping it, from the page.
        $crawler = $this->client->request('GET', '/blog/spring-draw');
        $this->client->submit($crawler->selectButton('Stop the preview')->form());
        self::assertResponseRedirects('/blog/spring-draw', 303);
        $this->client->followRedirect();
        self::assertSelectorNotExists('.child-banner');

        // It was the admin's own: kept in their session, so nobody else ever saw it.
        $this->signIn($this->createUser('Kim', [Role::EDITOR]));
        $this->client->request('GET', '/blog/spring-draw');
        self::assertSelectorNotExists('.child-banner');
    }

    public function testWhatCantBePreviewed(): void
    {
        $preview = $this->service(ThemePreview::class);

        self::assertSame('It\'s the theme the site uses.', $preview->reason('quire-theme-default'));
        self::assertStringContainsString('fills slots or adds variables to templates', (string) $preview->reason('quire-fixture-base'));
        self::assertStringContainsString('fills slots or adds variables to templates', (string) $preview->reason('quire-fixture-child'), 'Nor what inherits from it.');
        self::assertStringContainsString('isn\'t installed', (string) $preview->reason('quire-fixture-orphan'));

        $this->signIn('editor');
        $this->client->request('POST', '/_quire/theme-preview', ['_token' => $this->csrfToken('quire_theme_preview'), 'theme' => 'quire-fixture-default-child']);
        self::assertResponseStatusCodeSame(403, 'It takes the ability to change the theme.');
    }

    public function testWhatEachExtensionAdds(): void
    {
        $contributions = $this->service(ExtensionContributions::class);
        $hello = $contributions->of('quire-fixture-hello');

        self::assertNotEmpty($hello->routes);
        self::assertContains('quire-fixture-hello.moderate', $hello->abilities);
        self::assertFalse($hello->isEmpty());

        $core = $contributions->of('core');
        self::assertContains('post', $core->contentTypes);
        self::assertContains('site.title', $core->settings);
        self::assertContains('quire.language', $core->preferences);
        self::assertContains('core.alt-text', $core->checks);
        self::assertContains('content.transition', $core->actions);
        self::assertTrue($core->migrations);

        self::assertContains('theme.quire-theme-default.accent', $contributions->of('quire-theme-default')->settings);
        self::assertTrue($contributions->of('nothing-here')->isEmpty());
    }
}
