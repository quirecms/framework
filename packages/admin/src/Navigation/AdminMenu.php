<?php

declare(strict_types=1);

namespace Quire\Admin\Navigation;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Extend\Admin;
use Quire\Content\Type\ContentTypeDefinition;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin menu, as the current user may see it: a list per content type they may edit, and the entries
 * of {@see Admin::menu()}, by section.
 *
 * @api
 */
final readonly class AdminMenu
{
    /**
     * @param list<array{id: string, label: string, route: string, parameters: array<string, string>, ability: ?string, section: string, order: int, owner: string}> $entries
     */
    public function __construct(
        #[Autowire(param: Admin::MENU_PARAMETER)]
        private array $entries,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private RequestStack $requests,
        private TranslatorInterface $translator,
        private ContentLists $contentLists,
    ) {
    }

    /**
     * @return array<string, list<MenuItem>> Non-empty sections, in the order of {@see Admin::SECTIONS}
     */
    public function sections(): array
    {
        $request = $this->requests->getMainRequest();
        $currentPath = $request?->getPathInfo() ?? '';
        // A list per content type the user may edit, before the entries extensions add to the section.
        $entries = [...array_map(static fn (ContentTypeDefinition $type, int $position): array => [
            'id' => 'quire-admin.content.'.$type->id,
            'label' => $type->pluralLabel,
            'route' => 'quire_admin_content',
            'parameters' => ['type' => $type->id],
            'ability' => null,
            'section' => 'content',
            'order' => $position - 1000,
            'owner' => $type->owner,
        ], $this->contentLists->listableTypes(), array_keys($this->contentLists->listableTypes())), ...$this->entries];
        usort($entries, static fn (array $a, array $b): int => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
        $sections = array_fill_keys(Admin::SECTIONS, []);
        $best = null;

        foreach ($entries as $entry) {
            if (null !== $entry['ability'] && !$this->security->isGranted($entry['ability'])) {
                continue;
            }

            $url = $this->urls->generate($entry['route'], $entry['parameters']);
            $path = parse_url($url, \PHP_URL_PATH);
            $path = \is_string($path) ? $path : $url;
            // The entry whose page is the longest prefix of the current page is current: on a page below
            // "Posts", e.g. a post being edited, "Posts" is.
            $matches = $currentPath === $path || str_starts_with($currentPath, rtrim($path, '/').'/');

            if ($matches && (null === $best || \strlen($path) > $best[1])) {
                $best = [$entry['id'], \strlen($path)];
            }

            $sections[$entry['section']][] = ['entry' => $entry, 'url' => $url];
        }

        $menu = [];

        foreach (array_filter($sections) as $section => $items) {
            $menu[$section] = array_map(fn (array $item): MenuItem => new MenuItem(
                $item['entry']['id'],
                $this->translator->trans($item['entry']['label'], [], $item['entry']['owner']),
                $item['url'],
                $item['entry']['id'] === ($best[0] ?? null),
            ), $items);
        }

        return $menu;
    }
}
