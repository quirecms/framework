<?php

declare(strict_types=1);

namespace Quire\Admin\Navigation;

/**
 * An entry of the admin menu, as shown to the current user.
 *
 * @api
 */
final readonly class MenuItem
{
    public function __construct(
        public string $id,
        /** Translated */
        public string $label,
        public string $url,
        /** Whether it's the page being shown, or a page below it */
        public bool $current,
    ) {
    }
}
