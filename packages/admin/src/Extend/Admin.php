<?php

declare(strict_types=1);

namespace Quire\Admin\Extend;

use Quire\Admin\Dashboard\WidgetInterface;
use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderInterface;

/**
 * Adds to the admin interface: entries in its menu, and widgets on its dashboard.
 *
 *     new Extend\Routes(group: 'admin')
 *         ->get('/reviews', 'acme_reviews_admin', ReviewsAdminController::class),
 *
 *     new Admin()
 *         ->menu('acme-reviews', 'Reviews', 'acme_reviews_admin', ability: 'acme-reviews.moderate')
 *         ->widget(PendingReviewsWidget::class),
 *
 * Admin pages are routes in the group "admin" (see {@see \Quire\Extend\Routes}). Labels are translated
 * in the extension's translation domain, its ID. When the admin extension isn't enabled, this does nothing,
 * so an extension can use it without requiring the admin; wrap it in {@see \Quire\Extend\Conditional} if
 * the admin may not be installed at all.
 *
 * @api
 */
final class Admin implements ExtenderInterface
{
    /** The admin extension */
    public const string EXTENSION = 'quire-admin';

    /** @internal Parameter: list<{id, label, route, parameters, ability, section, order, owner}> */
    public const string MENU_PARAMETER = 'quire_admin.menu';

    /** @internal Parameter: list<{class, ability, order, owner}> */
    public const string WIDGETS_PARAMETER = 'quire_admin.widgets';

    /** @internal Tag of dashboard widgets */
    public const string WIDGET_TAG = 'quire_admin.widget';

    /** Where menu entries go, in this order */
    public const array SECTIONS = ['general', 'content', 'site', 'users', 'extensions'];

    /** @var list<array{id: string, label: string, route: string, parameters: array<string, string>, ability: ?string, section: string, order: int}> */
    private array $menu = [];

    /** @var list<array{class: string, ability: ?string, order: int}> */
    private array $widgets = [];

    /**
     * @param string                   $id         Unique in the menu; start it with your extension's ID
     * @param string                   $label      E.g. "Reviews", translated in your extension's domain
     * @param string                   $route      The page's route, in the group "admin"
     * @param array<string, string>    $parameters The route's parameters
     * @param string|null              $ability    Shown only to users with it (everyone in the admin has "quire.admin.access")
     * @param value-of<self::SECTIONS> $section    The menu section: "general", "content", "site", "users" or "extensions"
     * @param int                      $order      Position in the section, lowest first
     */
    public function menu(string $id, string $label, string $route, array $parameters = [], ?string $ability = null, string $section = 'extensions', int $order = 100): self
    {
        $this->menu[] = ['id' => $id, 'label' => $label, 'route' => $route, 'parameters' => $parameters, 'ability' => $ability, 'section' => $section, 'order' => $order];

        return $this;
    }

    /**
     * @param class-string<WidgetInterface> $class   A service rendering the widget
     * @param string|null                   $ability Shown only to users with it
     * @param int                           $order   Position on the dashboard, lowest first
     */
    public function widget(string $class, ?string $ability = null, int $order = 100): self
    {
        $this->widgets[] = ['class' => $class, 'ability' => $ability, 'order' => $order];

        return $this;
    }

    public function extend(ExtenderContext $context): void
    {
        if (!$context->isEnabled(self::EXTENSION)) {
            return;
        }

        /** @var list<array{id: string, owner: string}> $declared */
        $declared = $context->container()->getParameterBag()->all()[self::MENU_PARAMETER] ?? [];
        $taken = array_column($declared, 'owner', 'id');

        foreach ($this->menu as $entry) {
            $where = \sprintf('Admin::menu("%s")', $entry['id']);

            if (!\in_array($entry['section'], self::SECTIONS, true)) {
                throw $context->error($where, \sprintf('Unknown section "%s". Use one of: %s.', $entry['section'], implode(', ', self::SECTIONS)));
            }

            if (isset($taken[$entry['id']])) {
                throw $context->error($where, \sprintf('The menu entry is already added by "%s".', $taken[$entry['id']]));
            }

            $taken[$entry['id']] = $context->id;
            $context->appendToParameter(self::MENU_PARAMETER, [...$entry, 'owner' => $context->id]);
        }

        foreach ($this->widgets as $widget) {
            $where = \sprintf('Admin::widget("%s")', $widget['class']);

            if (!class_exists($widget['class']) || !is_a($widget['class'], WidgetInterface::class, true)) {
                throw $context->error($where, \sprintf('Widgets are classes implementing %s.', WidgetInterface::class));
            }

            $context->service($widget['class'])->addTag(self::WIDGET_TAG);
            $context->appendToParameter(self::WIDGETS_PARAMETER, [...$widget, 'owner' => $context->id]);
        }
    }
}
