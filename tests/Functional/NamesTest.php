<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\Settings\SettingsSchema;
use Quire\Testing\AdminTestCase;
use Quire\Testing\TestInstallation;
use Quire\Translation\Labels;

/**
 * The names of content types, roles and settings in the page's language: core's in its own texts, an
 * extension's in its, and a type's sentences worded whole ("Нова објава", "2 Beiträge durchsuchen…").
 */
final class NamesTest extends AdminTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin', 'quire-fixture-recipes'];

    public function testNamesInEachLanguage(): void
    {
        $labels = $this->service(Labels::class);
        $types = $this->service(ContentTypeRegistry::class);
        [$post, $page, $recipe] = [$types->get('post'), $types->get('page'), $types->get('recipe')];

        $this->locale('en');
        self::assertSame(['Post', 'Posts', 'New post', '1 post', 'Editor', 'Language'], [$labels->type($post), $labels->type($post, plural: true), $labels->phrase($post, 'new'), $labels->phrase($post, 'count', ['count' => 1]), $labels->role('editor'), $labels->setting($this->service(SettingsSchema::class)->get('site.locale'))]);
        self::assertSame(['New: Recipe', 'Recipes: 4', 'All: Recipes'], [$labels->phrase($recipe, 'new'), $labels->phrase($recipe, 'count', ['count' => 4]), $labels->phrase($recipe, 'all')], 'A type without phrases: neutral forms.');

        $this->locale('mk');
        self::assertSame(['Објава', 'Објави', 'Нова објава', 'Нова страница', 'Пребарај 54 објави…', 'Уредник', 'Сите', 'Јазик'], [$labels->type($post), $labels->type($post, plural: true), $labels->phrase($post, 'new'), $labels->phrase($page, 'new'), $labels->phrase($post, 'search', ['count' => 54]), $labels->role('editor'), $labels->role('guest'), $labels->setting($this->service(SettingsSchema::class)->get('site.locale'))]);
        self::assertSame('Ново: Recipe', $labels->phrase($recipe, 'new'), 'An extension\'s name it doesn\'t translate stays as declared.');

        $this->locale('de');
        self::assertSame(['Neuer Beitrag', 'Neue Seite', '2 Beiträge durchsuchen…'], [$labels->phrase($post, 'new'), $labels->phrase($page, 'new'), $labels->phrase($post, 'search', ['count' => 2])], 'Nouns keep their capitals.');
    }

    public function testAPhraseNoTextsWordIsFormattedAsDeclared(): void
    {
        $this->locale('mk');
        $gadget = $this->gadget(['count' => '{count, plural, one {# gadget} other {# gadgets}}']);

        self::assertSame('3 gadgets', $this->service(Labels::class)->phrase($gadget, 'count', ['count' => 3]));
    }

    public function testAPhraseTranslatedInAPlainDomainIsStillAnIcuMessage(): void
    {
        $this->locale('de');
        self::getContainer()->get('translator')->getCatalogue('de')->add(['{count, plural, one {# gadget} other {# gadgets}}' => '{count, plural, one {# Gerät} other {# Geräte}}'], 'quire-fixture-recipes');

        self::assertSame('3 Geräte', $this->service(Labels::class)->phrase($this->gadget(['count' => '{count, plural, one {# gadget} other {# gadgets}}']), 'count', ['count' => 3]));
    }

    public function testAPhraseThatCantBeFormattedFailsLoudly(): void
    {
        $gadget = $this->gadget(['empty' => 'No gadgets since {since, date}.']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The phrase "empty" of the type "gadget" can\'t be formatted in "en": ');

        $this->service(Labels::class)->phrase($gadget, 'empty', ['since' => 'last week']);
    }

    public function testEachOwnerHasItsDomain(): void
    {
        self::assertSame(['quire', 'site', 'acme-shop'], [Labels::domainOf('core'), Labels::domainOf('site'), Labels::domainOf('acme-shop')]);
    }

    public function testTheAdminInMacedonian(): void
    {
        $this->setSetting('site.locale', 'mk');
        $this->createUser('Eva', ['editor']);
        $this->signIn('admin');

        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertStringStartsWith('Објави', $crawler->filter('h1')->text());
        self::assertSame('Нова објава', trim(str_replace('＋', '', $crawler->filter('.content-head__tools a.q-btn')->text())));
        self::assertStringStartsWith('Пребарај', (string) $crawler->filter('main input[type=search][name=q]')->attr('placeholder'));
        self::assertSame(['Објави', 'Страници', 'Recipes'], $crawler->filter('nav.q-tabs a')->each(static fn ($a): string => $a->text()), 'The section\'s tabs: each type\'s name.');

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertContains('Уредник', $crawler->filter('.q-tag')->each(static fn ($tag): string => $tag->text()), 'Role chips.');
    }

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    /**
     * @param array<string, string> $phrases
     */
    private function gadget(array $phrases): ContentTypeDefinition
    {
        return new ContentTypeDefinition('gadget', 'Gadget', 'Gadgets', 'quire-fixture-recipes', [], '/gadgets/{slug}', 'created_at', 'desc', phrases: $phrases);
    }

    private function locale(string $locale): void
    {
        self::getContainer()->get('translator')->setLocale($locale);
    }
}
