<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Audit\AuditQuery;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Status;
use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;
use Quire\User\CreateUser;
use Quire\User\UserWriter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;

/**
 * Milestone 5's admin end to end: an extension that only declares a field type, a content type and a
 * setting (tests/Fixtures/extensions/recipes, no admin code) gets complete admin screens: a menu entry,
 * a list that sorts by its fields, a form with a control per field that checks values with the field
 * type, publishing, its setting, and its content in the roles' grid. Everything done is in the audit
 * log, with the console commands that do the same.
 */
final class AdminTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin', 'quire-fixture-recipes'];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        // Tests that commit leave entries behind; this one's transaction is rolled back.
        $this->connection()->executeStatement('DELETE FROM quire.audit_entry');
    }

    public function testAnExtensionWithoutAdminCodeGetsCompleteAdminScreens(): void
    {
        self::assertSame([], $this->adminCodeIn(TestInstallation::projectDir().'/tests/Fixtures/extensions/recipes'), 'The extension has no admin code.');

        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(1, $crawler->filter('nav a[href="/admin/content/recipe"]'), 'Recipes are in the menu.');

        // A form from the type's fields, with the admin's controls; the duration type picked the text one.
        $crawler = $this->client->request('GET', '/admin/content/recipe/new');
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['number', 'text', 'select', 'text', 'checkbox'],
            array_map(static fn (string $field): string => $crawler->filter('[name="fields['.$field.']"]')->last()->attr('type') ?? $crawler->filter('[name="fields['.$field.']"]')->last()->nodeName(), ['servings', 'cook_time', 'difficulty', 'ingredients', 'vegetarian']),
        );
        self::assertSelectorTextContains('label[for="field-cook_time"]', 'Cooking time');

        // The field type checks what's entered, and the mistake is linked from the summary.
        $form = $crawler->filter('button[name=intent][value="save"]')->form(['title' => 'Pancakes', 'body' => 'Mix, then fry.', 'fields[servings]' => '4', 'fields[cook_time]' => 'soon', 'fields[ingredients]' => 'flour, milk, eggs']);
        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['#field-cook_time'], $crawler->filter('.error-summary a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertStringContainsString('Expected a duration such as "1h 30m"', $crawler->filter('.error-summary')->text());

        $this->client->submit($crawler->filter('button[name=intent][value="transition:publish"]')->form(['fields[cook_time]' => '1h 30m']));
        $pancakes = $this->recipe('Pancakes');
        self::assertResponseRedirects('/admin/content/recipe/'.$pancakes->id, 303);
        self::assertSame([Status::PUBLISHED, 4, 90, ['flour', 'milk', 'eggs'], false], [$pancakes->status, $pancakes->fields['servings'], $pancakes->fields['cook_time'], $pancakes->fields['ingredients'], $pancakes->fields['vegetarian']]);

        $this->client->followRedirect();
        self::assertInputValueSame('fields[cook_time]', '90');
        $this->client->request('GET', '/recipes/pancakes');
        self::assertResponseIsSuccessful('Published on the site.');

        // The list sorts by a sortable field of the type.
        $quick = $this->client->request('GET', '/admin/content/recipe/new');
        $this->client->submit($quick->filter('button[name=intent][value="save"]')->form(['title' => 'Toast', 'body' => 'Toast it.', 'fields[servings]' => '1', 'fields[cook_time]' => '5']));
        $crawler = $this->client->request('GET', '/admin/content/recipe?sort=cook_time');
        self::assertSame(['Toast', 'Pancakes'], $crawler->filter('table .content-list__title')->each(static fn (Crawler $a): string => $a->text()));

        // An admin: the extension's setting, and the type in the roles' grid.
        $this->client->getCookieJar()->clear();
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/settings/quire-fixture-recipes');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form.settings-form')->form(['settings[quire-fixture-recipes.units]' => 'imperial']));
        self::assertSame('imperial', $this->settings()->get('quire-fixture-recipes.units'));
        $crawler = $this->client->request('GET', '/admin/roles/editor');
        self::assertCount(1, $crawler->filter('input[value="content.recipe.publish"]'), 'Who may publish recipes is a box in the grid.');

        // What was done is in the audit log, with the command that does the same.
        $log = $this->service(AuditLog::class)->find(new AuditQuery(subjectType: 'content', subjectId: $pancakes->id));
        self::assertSame(['content.transitioned', 'content.created'], array_map(static fn (AuditEntry $entry): string => $entry->action, $log->entries));
        self::assertSame('bin/console quire:content:transition '.$pancakes->id.' publish', $log->entries[0]->command);
        self::assertSame('Editor', $log->entries[0]->actorName);

        $crawler = $this->client->request('GET', '/admin/audit');
        self::assertResponseIsSuccessful();
        $summaries = $crawler->filter('.audit__summary')->each(static fn (Crawler $node): string => $node->text());
        self::assertContains('Changed the setting “Units”', $summaries);
        self::assertContains('Published “Pancakes”', $summaries);
        self::assertContains('Created “Toast”', $summaries);
    }

    public function testTheCommandOfAnActionIsShownAfterItButNeverASecret(): void
    {
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/settings/quire-fixture-recipes');
        $this->client->submit($crawler->filter('form.settings-form')->form(['settings[quire-fixture-recipes.units]' => 'imperial', 'settings[quire-fixture-recipes.api_key]' => 'secret-123']));
        $crawler = $this->client->followRedirect();

        self::assertSame('secret-123', $this->settings()->get('quire-fixture-recipes.api_key'));
        self::assertSame('bin/console quire:settings:set quire-fixture-recipes.units imperial', $crawler->filter('.command__text')->text(), 'Not the sensitive one.');

        $crawler = $this->client->request('GET', '/admin/audit?area=setting.');
        self::assertSame(['Changed the setting “Units”', 'Changed the setting “Nutrition API key”'], $crawler->filter('.audit__summary')->each(static fn (Crawler $node): string => $node->text()));
        self::assertStringNotContainsString('secret-123', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('secret-123', (string) json_encode($this->connection()->fetchAllAssociative('SELECT * FROM quire.audit_entry')));
    }

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    private function signIn(string $role): void
    {
        $this->service(UserWriter::class)->create(new CreateUser(ucfirst($role), $role.'@example.com', password: 'correct-horse-42', roles: [$role]));
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => $role, '_password' => 'correct-horse-42']);
    }

    private function recipe(string $title): Content
    {
        $recipe = $this->service(ContentRepository::class)->query('recipe')->withoutVisibilityScope()->where('slug', '=', strtolower($title))->first();
        self::assertInstanceOf(Content::class, $recipe);

        return $recipe;
    }

    /**
     * @return list<string> Files of the extension that mention the admin
     */
    private function adminCodeIn(string $directory): array
    {
        $files = [];

        foreach (new Finder()->files()->in($directory)->contains('Quire\\Admin') as $file) {
            $files[] = $file->getRelativePathname();
        }

        return $files;
    }
}
