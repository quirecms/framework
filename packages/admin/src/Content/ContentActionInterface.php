<?php

declare(strict_types=1);

namespace Quire\Admin\Content;

use Quire\Content\Content;

/**
 * An action of the admin's content lists, added with {@see \Quire\Admin\Extend\Admin::contentAction()}:
 * offered for each item it's available for, and for the selected items at once.
 *
 * @api
 */
interface ContentActionInterface
{
    /**
     * The action's label, e.g. "Feature", translated.
     */
    public function label(): string;

    /**
     * Whether the current user may take the action on the item now. Check abilities here, e.g. with
     * Symfony's Security::isGranted(): the list checks nothing else.
     */
    public function isAvailable(Content $item): bool;

    /**
     * Takes the action. An exception fails it for this item only: the message of a
     * {@see ContentActionException} is shown, others are logged and reported as an error.
     */
    public function apply(Content $item): void;
}
