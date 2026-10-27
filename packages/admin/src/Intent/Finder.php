<?php

declare(strict_types=1);

namespace Quire\Admin\Intent;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Controller\SettingsController;
use Quire\Admin\Controller\UsersController;
use Quire\Admin\Navigation\AdminMenu;
use Quire\Admin\Site\SettingsForm;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Finds what the words name, among what the person may open: content (by its text), people (by name,
 * username or email), settings (by label or key) and the admin's own pages (by their name).
 *
 * @internal
 *
 * @phpstan-type Found array{label: string, detail: string, url: string, status?: string}
 */
final readonly class Finder
{
    private const int EACH = 6;

    public function __construct(
        private ContentLists $lists,
        private UserRepository $users,
        private SettingsForm $settings,
        private AdminMenu $menu,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, list<Found>> By kind: content, people, settings, pages; only kinds with results
     */
    public function find(string $words): array
    {
        $words = trim($words);

        if ('' === $words) {
            return [];
        }

        $lower = mb_strtolower($words);
        $found = ['content' => [], 'people' => [], 'settings' => [], 'pages' => []];

        foreach ($this->menu->sections() as $items) {
            foreach ($items as $item) {
                if (str_contains(mb_strtolower($item->label), $lower)) {
                    $found['pages'][] = ['label' => $item->label, 'detail' => $this->translator->trans('Page of the admin', [], 'quire-admin'), 'url' => $item->url];
                }
            }
        }

        foreach ($this->lists->listableTypes() as $type) {
            foreach ($this->lists->query($type)->search($words, prefix: true)->limit(self::EACH)->get() as $item) {
                $found['content'][] = ['label' => $item->title, 'detail' => $this->translator->trans($type->label, [], $type->owner), 'url' => $this->urls->generate('quire_admin_content_edit', ['type' => $type->id, 'id' => $item->id]), 'status' => $item->status];
            }
        }

        if ($this->security->isGranted(UsersController::ABILITY)) {
            foreach ($this->users->search($words, limit: self::EACH)['users'] as $user) {
                $found['people'][] = ['label' => $user->displayName, 'detail' => $user->email ?? $user->username, 'url' => $this->urls->generate('quire_admin_user', ['id' => $user->id])];
            }
        }

        if ($this->security->isGranted(SettingsController::ABILITY)) {
            foreach ($this->settings->document() as $part) {
                foreach ($part['sentences'] as $sentence) {
                    $definition = $sentence['entry']['definition'];

                    if (str_contains(mb_strtolower($sentence['entry']['label']), $lower) || str_contains($definition->key, $lower)) {
                        $found['settings'][] = ['label' => $sentence['entry']['label'], 'detail' => $sentence['shown'], 'url' => $this->urls->generate('quire_admin_settings', ['group' => $part['group']]).'#'.$sentence['control']];
                    }
                }
            }
        }

        return array_map(static fn (array $list): array => \array_slice($list, 0, self::EACH), array_filter($found));
    }
}
