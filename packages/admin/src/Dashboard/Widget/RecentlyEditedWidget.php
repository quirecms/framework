<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard\Widget;

use Quire\Admin\Dashboard\WidgetInterface;
use Quire\Content\ContentRepository;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The items changed last, of the types the user may edit.
 *
 * @internal
 */
final readonly class RecentlyEditedWidget implements WidgetInterface
{
    private const int LIMIT = 8;

    public function __construct(
        private ContentTypeRegistry $types,
        private ContentRepository $content,
        private Security $security,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function title(): string
    {
        return $this->translator->trans('Recently edited', [], 'quire-admin');
    }

    public function render(): string
    {
        $types = array_keys(array_filter(
            $this->types->all(),
            fn (ContentTypeDefinition $type): bool => $this->security->isGranted('content.'.$type->id.'.edit_own') || $this->security->isGranted('content.'.$type->id.'.edit_any'),
        ));

        if ([] === $types) {
            return '';
        }

        $items = $this->content->query(...$types)->withoutVisibilityScope()->orderBy('updated_at', 'desc')->limit(self::LIMIT)->get();

        return $this->twig->render('@quire_admin/dashboard/recently_edited.html.twig', ['items' => $items, 'types' => $this->types->all()]);
    }
}
