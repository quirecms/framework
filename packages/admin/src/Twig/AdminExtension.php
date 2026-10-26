<?php

declare(strict_types=1);

namespace Quire\Admin\Twig;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Navigation\AdminMenu;
use Quire\Admin\Navigation\MenuItem;
use Quire\Admin\Preferences\UnderTheHood;
use Quire\Content\Type\ContentTypeDefinition;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        private ContentLists $contentLists,
        private UnderTheHood $hood,
        private Security $security,
        private UrlGeneratorInterface $urls,
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

    /**
     * What the current user may create, for the "New" menu.
     *
     * @return list<array{label: string, url: string}>
     */
    #[AsTwigFunction(name: 'quire_admin_new')]
    public function new(): array
    {
        return array_values(array_map(
            fn (ContentTypeDefinition $type): array => ['label' => $type->label, 'url' => $this->urls->generate('quire_admin_content_new', ['type' => $type->id])],
            array_filter($this->contentLists->listableTypes(), fn (ContentTypeDefinition $type): bool => $this->security->isGranted('content.'.$type->id.'.create')),
        ));
    }

    #[AsTwigFunction(name: 'quire_admin_hood')]
    public function hood(): bool
    {
        return $this->hood->isOn();
    }
}
