<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard\Widget;

use Quire\Admin\Dashboard\WidgetInterface;
use Quire\Content\ContentRepository;
use Quire\Content\Status;
use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * How many items of each content type the user may edit are published, scheduled and drafts.
 *
 * @internal
 */
final readonly class ContentWidget implements WidgetInterface
{
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
        return $this->translator->trans('Content', [], 'quire-admin');
    }

    public function render(): string
    {
        $rows = [];

        foreach ($this->types->all() as $type) {
            if (!$this->security->isGranted('content.'.$type->id.'.edit_own') && !$this->security->isGranted('content.'.$type->id.'.edit_any')) {
                continue;
            }

            $query = $this->content->query($type->id)->withoutVisibilityScope();
            $rows[] = [
                'type' => $type,
                'published' => $query->status(Status::PUBLISHED)->count(),
                'scheduled' => $query->status(Status::SCHEDULED)->count(),
                'drafts' => $query->status(Status::DRAFT)->count(),
            ];
        }

        return [] === $rows ? '' : $this->twig->render('@quire_admin/dashboard/content.html.twig', ['rows' => $rows]);
    }
}
