<?php

declare(strict_types=1);

namespace Quire\Admin\Twig;

use Quire\Admin\Navigation\AdminMenu;
use Quire\Admin\Navigation\MenuItem;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig functions of the admin's templates.
 *
 * @internal
 */
final readonly class AdminExtension
{
    public function __construct(
        private AdminMenu $menu,
    ) {
    }

    /**
     * @return array<string, list<MenuItem>>
     */
    #[AsTwigFunction(name: 'quire_admin_menu')]
    public function menu(): array
    {
        return $this->menu->sections();
    }
}
