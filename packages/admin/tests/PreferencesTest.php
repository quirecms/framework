<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Admin\Preferences\AdminPreferences;
use Quire\Testing\AdminTestCase;
use Quire\User\Preference\UserPreferences;

/**
 * Each person's preferences of the admin, kept with them, and the keyboard.
 */
final class PreferencesTest extends AdminTestCase
{
    public function testThePreferencesShowAndFollowThePerson(): void
    {
        $editor = $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin');

        self::assertSame('preferences', $crawler->filter('dialog#preferences')->attr('data-controller'), 'A sheet on every page.');
        self::assertSame('/admin/preferences', $crawler->selectLink('Your preferences')->attr('href'), 'A page of its own without JavaScript.');
        self::assertSame(['Like the site', 'Deutsch', 'English'], $crawler->filter('dialog input[name=language]')->each(static fn ($input): string => $input->closest('label')?->text() ?? ''));

        // Saved as they're changed, with JavaScript.
        $this->client->request('POST', '/admin/preferences', ['_token' => (string) $crawler->filter('form.prefs input[name=_token]')->attr('value'), 'theme' => 'dark', 'text' => 'lg', 'contrast' => 'high', 'motion' => 'reduce', 'density' => 'compact', 'hood_shown' => '1'], server: ['HTTP_X_QUIRE_REQUEST' => 'fetch']);
        self::assertResponseStatusCodeSame(204);

        $crawler = $this->client->request('GET', '/admin');
        $html = $crawler->filter('html');
        self::assertSame(['dark', 'high', 'lg', 'compact', 'reduce', null], [$html->attr('data-theme'), $html->attr('data-contrast'), $html->attr('data-text'), $html->attr('data-density'), $html->attr('data-motion'), $html->attr('data-hood')]);
        self::assertNotNull($crawler->filter('dialog input[name=theme][value=dark]')->attr('checked'));
        self::assertFalse($this->service(UserPreferences::class)->get($editor, AdminPreferences::HOOD), 'Switched off by the sheet.');

        // Only their own, and what fits.
        $this->client->request('POST', '/admin/preferences', ['_token' => (string) $crawler->filter('form.prefs input[name=_token]')->attr('value'), 'theme' => 'purple', 'back' => '/admin/users']);
        self::assertResponseRedirects('/admin/users', 303);
        self::assertSame('dark', $this->service(UserPreferences::class)->get($editor, AdminPreferences::THEME));
        self::assertSame('system', $this->service(UserPreferences::class)->get($this->createUser('Kim'), AdminPreferences::THEME));
        $this->client->request('POST', '/admin/preferences', ['theme' => 'light']);
        self::assertResponseStatusCodeSame(403, 'Without the form\'s token.');
    }

    public function testTheAdminInTheLanguageEachPersonReadsItIn(): void
    {
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin/preferences');
        $this->client->submit($crawler->filter('form.prefs')->form(['language' => 'de']));
        $crawler = $this->client->followRedirect();

        self::assertSame('de', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextSame('h1', 'Deine Einstellungen');
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('html[lang=de]', 'The site stays in its own language.');

        $this->client->submit($this->client->request('GET', '/admin/preferences')->filter('form.prefs')->form(['language' => '']));
        self::assertSame('en', $this->client->followRedirect()->filter('html')->attr('lang'));
    }

    public function testTheSwitchesElsewhereAreThePreferencesToo(): void
    {
        $admin = $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertSame('on', $crawler->filter('html')->attr('data-hood'), 'On for admins until they choose.');

        $this->client->submit($crawler->filter('.q-switch')->form());
        self::assertFalse($this->service(UserPreferences::class)->get($admin, AdminPreferences::HOOD));
        $crawler = $this->client->followRedirect();
        self::assertNull($crawler->filter('html')->attr('data-hood'));
    }
}
