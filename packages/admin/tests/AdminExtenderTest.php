<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Quire\Admin\Dashboard\Widget\ContentWidget;
use Quire\Admin\Extend\Admin;
use Quire\Admin\Tests\Fixtures\StarsWidget;
use Quire\Admin\Tests\Fixtures\TitleLengthColumn;
use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AdminExtenderTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
    }

    public function testAddsMenuEntriesAndWidgets(): void
    {
        $this->apply(new Admin()
            ->menu('acme-reviews.list', 'Reviews', 'acme_reviews_admin', ['status' => 'new'], ability: 'acme-reviews.moderate', section: 'content', order: 20)
            ->widget(ContentWidget::class, ability: 'acme-reviews.moderate', order: 5));

        self::assertSame([[
            'id' => 'acme-reviews.list', 'label' => 'Reviews', 'route' => 'acme_reviews_admin', 'parameters' => ['status' => 'new'],
            'ability' => 'acme-reviews.moderate', 'section' => 'content', 'order' => 20, 'owner' => 'acme-reviews',
        ]], $this->container->getParameterBag()->all()[Admin::MENU_PARAMETER] ?? null);
        self::assertSame([['class' => ContentWidget::class, 'ability' => 'acme-reviews.moderate', 'order' => 5, 'owner' => 'acme-reviews']], $this->container->getParameterBag()->all()[Admin::WIDGETS_PARAMETER] ?? null);
        self::assertTrue($this->container->getDefinition(ContentWidget::class)->hasTag(Admin::WIDGET_TAG));
    }

    public function testAddsToContentLists(): void
    {
        $this->apply(new Admin()->contentColumn('acme-reviews.length', TitleLengthColumn::class, types: ['post'], order: 5));

        self::assertSame([[
            'id' => 'acme-reviews.length', 'kind' => 'column', 'class' => TitleLengthColumn::class, 'types' => ['post'], 'order' => 5, 'owner' => 'acme-reviews',
        ]], $this->container->getParameterBag()->all()[Admin::CONTENT_LIST_PARAMETER] ?? null);
        self::assertTrue($this->container->getDefinition(TitleLengthColumn::class)->hasTag(Admin::CONTENT_LIST_TAG));

        $this->assertFails(new Admin()->contentColumn('acme-reviews.length', TitleLengthColumn::class), 'A column with this ID is already added by "acme-reviews".');
        $this->assertFails(new Admin()->contentFilter('acme-reviews.length', TitleLengthColumn::class), 'Content list filters are classes implementing Quire\Admin\Content\ContentFilterInterface.'); // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertFails(new Admin()->contentAction('Acme Reviews', TitleLengthColumn::class), 'IDs are lowercase letters and digits'); // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertFails(new Admin()->contentColumn('acme-reviews.other', TitleLengthColumn::class, types: []), 'Give the content types as a list of IDs');
    }

    public function testAddsFieldWidgets(): void
    {
        $this->apply(new Admin()->fieldWidget('acme-reviews.stars', StarsWidget::class));

        self::assertSame(['acme-reviews.stars' => ['class' => StarsWidget::class, 'owner' => 'acme-reviews']], $this->container->getParameterBag()->all()[Admin::FIELD_WIDGETS_PARAMETER] ?? null);
        self::assertTrue($this->container->getDefinition(StarsWidget::class)->hasTag(Admin::FIELD_WIDGET_TAG));

        $this->assertFails(new Admin()->fieldWidget('acme-reviews.stars', StarsWidget::class), 'The widget is already added by "acme-reviews".');
        $this->assertFails(new Admin()->fieldWidget('number', StarsWidget::class), 'This is one of the admin\'s own widgets.');
        $this->assertFails(new Admin()->fieldWidget('acme-reviews.other', TitleLengthColumn::class), 'Field widgets are classes implementing Quire\Admin\Content\Form\FieldWidgetInterface.'); // @phpstan-ignore argument.type (testing the runtime check)
    }

    public function testContentListsHaveTheirParameterWithoutAdditions(): void
    {
        $this->apply(new Admin());

        self::assertSame([], $this->container->getParameterBag()->all()[Admin::CONTENT_LIST_PARAMETER] ?? null);
    }

    public function testDoesNothingWithoutTheAdmin(): void
    {
        $this->apply(new Admin()->menu('acme-reviews.list', 'Reviews', 'acme_reviews_admin')->widget(\stdClass::class), enabled: ['acme-reviews']); // @phpstan-ignore argument.type (not checked: nothing happens)

        self::assertArrayNotHasKey(Admin::MENU_PARAMETER, $this->container->getParameterBag()->all());
        self::assertArrayNotHasKey(Admin::WIDGETS_PARAMETER, $this->container->getParameterBag()->all());
    }

    public function testChecksWhatItsGiven(): void
    {
        $this->assertFails(new Admin()->menu('acme-reviews.list', 'Reviews', 'acme_reviews_admin', section: 'tools'), 'Unknown section "tools". Use one of: general, content, users, activity, site, extensions.'); // @phpstan-ignore argument.type (testing the runtime check)
        $this->assertFails(new Admin()->widget(\stdClass::class), 'Widgets are classes implementing Quire\Admin\Dashboard\WidgetInterface.'); // @phpstan-ignore argument.type (testing the runtime check)

        $this->apply(new Admin()->menu('acme-reviews.list', 'Reviews', 'acme_reviews_admin'));
        $this->assertFails(new Admin()->menu('acme-reviews.list', 'Reviews', 'acme_reviews_other'), 'The menu entry is already added by "acme-reviews".');
    }

    /**
     * @param list<string> $enabled
     */
    private function apply(Admin $extender, array $enabled = [Admin::EXTENSION, 'acme-reviews']): void
    {
        $extender->extend(new ExtenderContext('acme-reviews', __DIR__, $this->container, $enabled));
    }

    private function assertFails(Admin $extender, string $message): void
    {
        try {
            $this->apply($extender);
            self::fail('The extender accepted it.');
        } catch (ExtenderException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }
}
