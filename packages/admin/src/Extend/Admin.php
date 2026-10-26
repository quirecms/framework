<?php

declare(strict_types=1);

namespace Quire\Admin\Extend;

use Quire\Admin\Content\ContentActionInterface;
use Quire\Admin\Content\ContentColumnInterface;
use Quire\Admin\Content\ContentFilterInterface;
use Quire\Admin\Content\Form\CoreWidgets;
use Quire\Admin\Content\Form\FieldWidgetInterface;
use Quire\Admin\Dashboard\WidgetInterface;
use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderInterface;

/**
 * Adds to the admin interface: entries in its menu, widgets on its dashboard, columns, filters and
 * actions to its content lists, and widgets for fields to its editor.
 *
 *     new Extend\Routes(group: 'admin')
 *         ->get('/reviews', 'acme_reviews_admin', ReviewsAdminController::class),
 *
 *     new Admin()
 *         ->menu('acme-reviews', 'Reviews', 'acme_reviews_admin', ability: 'acme-reviews.moderate')
 *         ->widget(PendingReviewsWidget::class)
 *         ->contentColumn('acme-reviews.rating', RatingColumn::class, types: ['product'])
 *         ->contentFilter('acme-reviews.rated', RatedFilter::class, types: ['product'])
 *         ->contentAction('acme-reviews.feature', FeatureAction::class),
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

    /** @internal Parameter: list<{id, kind, class, types, order, owner}> of content list columns, filters and actions */
    public const string CONTENT_LIST_PARAMETER = 'quire_admin.content_list';

    /** @internal Tag of content list columns, filters and actions */
    public const string CONTENT_LIST_TAG = 'quire_admin.content_list';

    /** @internal What content lists can be extended with, and the interface each must implement */
    public const array CONTENT_LIST_KINDS = [
        'column' => ContentColumnInterface::class,
        'filter' => ContentFilterInterface::class,
        'action' => ContentActionInterface::class,
    ];

    /** @internal Parameter: array<name, {class, owner}> of the widgets extensions add to the editor */
    public const string FIELD_WIDGETS_PARAMETER = 'quire_admin.field_widgets';

    /** @internal Tag of field widgets */
    public const string FIELD_WIDGET_TAG = 'quire_admin.field_widget';

    private const string ID_PATTERN = '/^[a-z0-9]+(?:[-.][a-z0-9]+)*$/';

    /** Where menu entries go, in this order */
    public const array SECTIONS = ['general', 'content', 'users', 'activity', 'site', 'extensions'];

    /** @var list<array{id: string, label: string, route: string, parameters: array<string, string>, ability: ?string, section: string, order: int}> */
    private array $menu = [];

    /** @var list<array{class: string, ability: ?string, order: int}> */
    private array $widgets = [];

    /** @var list<array{id: string, kind: string, class: string, types: ?list<string>, order: int}> */
    private array $contentList = [];

    /** @var array<string, string> Classes by widget name */
    private array $fieldWidgets = [];

    /**
     * @param string                   $id         Unique in the menu; start it with your extension's ID
     * @param string                   $label      E.g. "Reviews", translated in your extension's domain
     * @param string                   $route      The page's route, in the group "admin"
     * @param array<string, string>    $parameters The route's parameters
     * @param string|null              $ability    Shown only to users with it (everyone in the admin has "quire.admin.access")
     * @param value-of<self::SECTIONS> $section    The menu section, a part of the rail: "general" (Home), "content", "users" (People), "activity", "site" or "extensions" (More)
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

    /**
     * A column of the content lists, after the built-in ones.
     *
     * @param string                               $id    Unique among columns; start it with your extension's ID
     * @param class-string<ContentColumnInterface> $class A service
     * @param list<string>|null                    $types The content types whose lists get it; null for all
     * @param int                                  $order Position among the added columns, lowest first
     */
    public function contentColumn(string $id, string $class, ?array $types = null, int $order = 100): self
    {
        $this->contentList[] = ['id' => $id, 'kind' => 'column', 'class' => $class, 'types' => $types, 'order' => $order];

        return $this;
    }

    /**
     * A filter of the content lists, next to the search box.
     *
     * @param string                               $id    Unique among filters; it's the filter's name in the list's URL
     * @param class-string<ContentFilterInterface> $class A service
     * @param list<string>|null                    $types The content types whose lists get it; null for all
     */
    public function contentFilter(string $id, string $class, ?array $types = null, int $order = 100): self
    {
        $this->contentList[] = ['id' => $id, 'kind' => 'filter', 'class' => $class, 'types' => $types, 'order' => $order];

        return $this;
    }

    /**
     * An action of the content lists, for single items and for the selected ones.
     *
     * @param string                               $id    Unique among actions; start it with your extension's ID
     * @param class-string<ContentActionInterface> $class A service
     * @param list<string>|null                    $types The content types whose lists get it; null for all
     */
    public function contentAction(string $id, string $class, ?array $types = null, int $order = 100): self
    {
        $this->contentList[] = ['id' => $id, 'kind' => 'action', 'class' => $class, 'types' => $types, 'order' => $order];

        return $this;
    }

    /**
     * How the editor shows fields whose widget has this name, e.g. those of a field type that an
     * extension adds (see {@see \Quire\Content\Field\FieldTypeInterface::widget()}).
     *
     * @param string                             $name  E.g. "color"; core's widgets (text, number, …) can't be replaced
     * @param class-string<FieldWidgetInterface> $class A service
     */
    public function fieldWidget(string $name, string $class): self
    {
        $this->fieldWidgets[$name] = $class;

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

        /** @var list<array{id: string, kind: string, owner: string}> $added */
        $added = $context->container()->getParameterBag()->all()[self::CONTENT_LIST_PARAMETER] ?? [];
        // The admin's own extend.php runs this too, so the lists find the parameter even when nothing is added.
        $context->container()->setParameter(self::CONTENT_LIST_PARAMETER, $added);
        $owners = [];

        foreach ($added as $entry) {
            $owners[$entry['kind']][$entry['id']] = $entry['owner'];
        }

        foreach ($this->contentList as $entry) {
            $where = \sprintf('Admin::content%s("%s")', ucfirst($entry['kind']), $entry['id']);
            $interface = self::CONTENT_LIST_KINDS[$entry['kind']];

            if (1 !== preg_match(self::ID_PATTERN, $entry['id'])) {
                throw $context->error($where, 'IDs are lowercase letters and digits, with single hyphens or dots between them, e.g. "acme-reviews.rating".');
            }

            if (isset($owners[$entry['kind']][$entry['id']])) {
                throw $context->error($where, \sprintf('A %s with this ID is already added by "%s".', $entry['kind'], $owners[$entry['kind']][$entry['id']]));
            }

            if (!class_exists($entry['class']) || !is_a($entry['class'], $interface, true)) {
                throw $context->error($where, \sprintf('Content list %ss are classes implementing %s.', $entry['kind'], $interface));
            }

            if ([] === $entry['types']) {
                throw $context->error($where, 'Give the content types as a list of IDs, e.g. ["post"], or null for all types.');
            }

            $owners[$entry['kind']][$entry['id']] = $context->id;
            $context->service($entry['class'])->addTag(self::CONTENT_LIST_TAG);
            $context->appendToParameter(self::CONTENT_LIST_PARAMETER, [...$entry, 'owner' => $context->id]);
        }

        /** @var array<string, array{class: string, owner: string}> $widgets */
        $widgets = $context->container()->getParameterBag()->all()[self::FIELD_WIDGETS_PARAMETER] ?? [];

        foreach ($this->fieldWidgets as $name => $class) {
            $where = \sprintf('Admin::fieldWidget("%s")', $name);

            if (1 !== preg_match(self::ID_PATTERN, $name)) {
                throw $context->error($where, 'Widget names are lowercase letters and digits, with single hyphens or dots between them, e.g. "acme-maps.location".');
            }

            if (\in_array($name, CoreWidgets::NAMES, true)) {
                throw $context->error($where, 'This is one of the admin\'s own widgets. Give yours its own name, and name it in the field type\'s widget().');
            }

            if (isset($widgets[$name])) {
                throw $context->error($where, \sprintf('The widget is already added by "%s".', $widgets[$name]['owner']));
            }

            if (!class_exists($class) || !is_a($class, FieldWidgetInterface::class, true)) {
                throw $context->error($where, \sprintf('Field widgets are classes implementing %s.', FieldWidgetInterface::class));
            }

            $context->service($class)->addTag(self::FIELD_WIDGET_TAG);
            $widgets[$name] = ['class' => $class, 'owner' => $context->id];
        }

        $context->container()->setParameter(self::FIELD_WIDGETS_PARAMETER, $widgets);
    }
}
