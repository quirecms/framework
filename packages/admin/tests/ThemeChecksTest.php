<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Testing\AdminTestCase;
use Quire\Testing\TestInstallation;

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
}
