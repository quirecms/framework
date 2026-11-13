<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Extension\ExtensionManager;
use Quire\Extension\InstalledExtensions;
use Quire\Testing\AdminTestCase;
use Quire\Testing\TestInstallation;
use Quire\Theme\Internal\ThemeLinter;
use Quire\Theme\ThemeCheckInterface;
use Quire\Theme\ThemeProblem;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The admin's themes page shows what "quire:theme:lint" finds in the theme in use, so an owner who
 * never runs it sees it too.
 */
final class ThemeChecksTest extends AdminTestCase
{
    #[\Override]
    protected static ?string $theme = 'quire-fixture-inline-code';

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testTheThemeInUseShowsWhatTheChecksFind(): void
    {
        // The fixture theme's pages have no sign-in form of their own.
        $this->signIn($this->createUser('Admin', ['admin'], username: 'admin'));
        $crawler = $this->client->request('GET', '/admin/themes');

        self::assertResponseIsSuccessful();
        $active = $crawler->filter('.look--active');
        self::assertStringContainsString('warnings about the theme', $active->filter('details.look__checks summary')->text());
        self::assertStringContainsString('which the site\'s Content-Security-Policy blocks', $active->filter('details.look__checks')->text());
    }

    public function testEveryOtherThemeShowsWhatItsChecksFindBeforeItsUsed(): void
    {
        $this->signIn($this->createUser('Admin', ['admin'], username: 'admin'));
        $crawler = $this->client->request('GET', '/admin/themes');

        $headTags = $crawler->filter('.look')->reduce(static fn (Crawler $card): bool => str_contains($card->text(), 'quire/fixture-theme-head-tags'));
        self::assertCount(1, $headTags);
        self::assertStringContainsString('quire_head() prints it too', $headTags->filter('details.look__checks')->text());
    }

    /**
     * Nothing of an installed theme that isn't used runs in the admin: a check that runs a theme's code
     * isn't even called for it, whatever it would do.
     */
    public function testChecksThatRunAThemesCodeRunOnlyForTheThemeInUse(): void
    {
        $rendering = new class implements ThemeCheckInterface {
            /** @var list<string> */
            public array $called = [];

            public function rendersTheme(): bool
            {
                return true;
            }

            public function check(string $theme, array $templates): array
            {
                $this->called[] = $theme;

                return [ThemeProblem::error('ran')];
            }
        };
        $reading = new class implements ThemeCheckInterface {
            /** @var list<string> */
            public array $called = [];

            public function rendersTheme(): bool
            {
                return false;
            }

            public function check(string $theme, array $templates): array
            {
                $this->called[] = $theme;

                return [];
            }
        };
        $linter = new ThemeLinter($this->service(Environment::class), $this->service(InstalledExtensions::class), $this->service(ExtensionManager::class), [$rendering, $reading]);

        $linter->lint('quire-fixture-head-tags', render: false);
        $linter->lint('quire-fixture-inline-code', render: true);

        self::assertSame(['quire-fixture-inline-code'], $rendering->called, 'Only for the theme the admin uses, or that quire:theme:lint is asked about.');
        self::assertSame(['quire-fixture-head-tags', 'quire-fixture-inline-code'], $reading->called);
    }
}
