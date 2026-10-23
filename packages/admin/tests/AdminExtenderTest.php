<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Quire\Admin\Dashboard\Widget\ContentWidget;
use Quire\Admin\Extend\Admin;
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

    public function testDoesNothingWithoutTheAdmin(): void
    {
        $this->apply(new Admin()->menu('acme-reviews.list', 'Reviews', 'acme_reviews_admin')->widget(\stdClass::class), enabled: ['acme-reviews']); // @phpstan-ignore argument.type (not checked: nothing happens)

        self::assertArrayNotHasKey(Admin::MENU_PARAMETER, $this->container->getParameterBag()->all());
        self::assertArrayNotHasKey(Admin::WIDGETS_PARAMETER, $this->container->getParameterBag()->all());
    }

    public function testChecksWhatItsGiven(): void
    {
        $this->assertFails(new Admin()->menu('acme-reviews.list', 'Reviews', 'acme_reviews_admin', section: 'tools'), 'Unknown section "tools". Use one of: general, content, site, users, extensions.'); // @phpstan-ignore argument.type (testing the runtime check)
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
