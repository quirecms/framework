<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard;

/**
 * A box on the admin's dashboard, added with {@see \Quire\Admin\Extend\Admin::widget()}. It's a service,
 * so it can depend on others, e.g. Twig to render a template.
 *
 * @api
 */
interface WidgetInterface
{
    /**
     * The widget's heading, translated.
     */
    public function title(): string;

    /**
     * The widget's content, as HTML. An empty string hides the widget, e.g. when there's nothing to show.
     */
    public function render(): string;
}
