<?php

declare(strict_types=1);

namespace Quire\Admin\Setup;

use Quire\Admin\Content\ContentLists;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Setup\SetupStep;
use Quire\Setup\SetupStepInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Write your first post": done once anything of a listable type is written, trashed included.
 * Offered to whoever may write one.
 *
 * @internal
 */
final readonly class FirstPostStep implements SetupStepInterface
{
    public function __construct(
        private ContentLists $lists,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function step(): ?SetupStep
    {
        $types = $this->lists->listableTypes();

        if ([] === $types) {
            return null;
        }

        return new SetupStep(
            'quire-admin.first-post',
            $this->translator->trans('Write your first post', [], 'quire-admin'),
            $this->translator->trans('A title is enough to start. It stays a draft until you publish it.', [], 'quire-admin'),
            array_any($types, fn (ContentTypeDefinition $type): bool => $this->lists->query($type, withTrashed: true)->limit(1)->count() > 0),
            $this->urls->generate('quire_admin_content_new', ['type' => $types[0]->id]),
            $this->translator->trans('Start writing', [], 'quire-admin'),
        );
    }
}
