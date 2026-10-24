<?php

declare(strict_types=1);

namespace Quire\Admin\Permission;

use Quire\Content\Type\ContentTypeRegistry;
use Quire\Extension\InstalledExtensions;
use Quire\Permission\Permissions;
use Quire\Routing\AdminAccessListener;
use Quire\User\Role;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The abilities as people read them: those of content as a grid of types and actions, the others by the
 * extension that declares them and their group, each with its label. And what doesn't fit together in a
 * set of abilities, e.g. editing content without being able to open the admin.
 *
 * @internal
 *
 * @phpstan-type Entry array{name: string, label: string, strict: bool, admin: bool}
 * @phpstan-type Grid array{actions: array<string, string>, types: list<array{id: string, label: string, abilities: array<string, string>}>}
 * @phpstan-type Section array{owner: string, label: string, groups: array<string, array{label: string, abilities: list<Entry>}>}
 */
final readonly class AbilityCatalog
{
    /** The actions of content, in the grid's order, by ability suffix */
    public const array ACTIONS = ['view', 'view_drafts', 'create', 'edit_own', 'edit_any', 'publish', 'review', 'delete'];

    /** Content actions that are done in the admin */
    private const array ADMIN_ACTIONS = ['create', 'edit_own', 'edit_any', 'publish', 'review', 'delete'];

    public function __construct(
        private Permissions $permissions,
        private ContentTypeRegistry $types,
        private InstalledExtensions $extensions,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return Grid
     */
    public function contentGrid(): array
    {
        $types = [];

        foreach ($this->types->all() as $type) {
            $abilities = [];

            foreach (self::ACTIONS as $action) {
                $name = \sprintf('content.%s.%s', $type->id, $action);

                if ($this->permissions->has($name)) {
                    $abilities[$action] = $name;
                }
            }

            $types[] = ['id' => $type->id, 'label' => $this->translator->trans($type->pluralLabel, [], $type->owner), 'abilities' => $abilities];
        }

        return [
            'actions' => array_combine(self::ACTIONS, array_map(fn (string $action): string => $this->translator->trans('abilities.action.'.$action, [], 'quire-admin'), self::ACTIONS)),
            'types' => $types,
        ];
    }

    /**
     * The abilities that aren't content's, by the extension that declares them (core first), then group.
     *
     * @param list<string>|null $only Only these
     *
     * @return list<Section>
     */
    public function sections(?array $only = null): array
    {
        $sections = [];

        foreach ($this->permissions->abilities() as $name => $ability) {
            if ($this->isContent($name) || (null !== $only && !\in_array($name, $only, true))) {
                continue;
            }

            $owner = $ability['owner'];
            $group = $ability['group'] ?? '';
            $sections[$owner] ??= ['owner' => $owner, 'label' => $this->ownerLabel($owner), 'groups' => []];
            $sections[$owner]['groups'][$group] ??= ['label' => $this->groupLabel($group), 'abilities' => []];
            $sections[$owner]['groups'][$group]['abilities'][] = $this->entry($name);
        }

        uasort($sections, static fn (array $a, array $b): int => ['core' !== $a['owner'], $a['label']] <=> ['core' !== $b['owner'], $b['label']]);

        return array_values($sections);
    }

    /**
     * @return Entry
     */
    public function entry(string $name): array
    {
        $ability = $this->permissions->abilities()[$name];

        return [
            'name' => $name,
            'label' => $this->label($name),
            'strict' => $ability['strict'],
            'admin' => 'admin' === $ability['group'],
        ];
    }

    public function label(string $name): string
    {
        $ability = $this->permissions->abilities()[$name] ?? null;

        return null === $ability ? $name : $this->translator->trans($ability['label'], [], $ability['owner']);
    }

    public function isContent(string $name): bool
    {
        return 'content' === ($this->permissions->abilities()[$name]['group'] ?? null) && str_starts_with($name, 'content.');
    }

    /**
     * What doesn't fit together in a role's abilities, in plain words: abilities that can only be used in
     * the admin, without being able to open it.
     *
     * @param list<string> $abilities
     *
     * @return list<string>
     */
    public function problems(string $role, array $abilities): array
    {
        if (Role::ADMIN === $role || Role::GUEST === $role || \in_array(AdminAccessListener::ABILITY, $abilities, true)) {
            return [];
        }

        $needAdmin = array_filter($abilities, fn (string $name): bool => ($this->permissions->abilities()[$name]['group'] ?? null) === 'admin'
            || ($this->isContent($name) && \in_array(substr($name, (int) strrpos($name, '.') + 1), self::ADMIN_ACTIONS, true)));

        if ([] === $needAdmin) {
            return [];
        }

        return [$this->translator->trans('They may {abilities}, which is done in the admin, but they can\'t open it. Add “{access}”, or remove those.', [
            'abilities' => implode(', ', array_map(fn (string $name): string => mb_strtolower($this->label($name)), \array_slice(array_values($needAdmin), 0, 3))).(\count($needAdmin) > 3 ? ' …' : ''),
            'access' => $this->label(AdminAccessListener::ABILITY),
        ], 'quire-admin')];
    }

    private function ownerLabel(string $owner): string
    {
        return 'core' === $owner ? 'Quire' : ($this->extensions->all()[$owner]->title ?? $owner);
    }

    private function groupLabel(string $group): string
    {
        if ('' === $group) {
            return '';
        }

        $key = 'abilities.group.'.$group;
        $label = $this->translator->trans($key, [], 'quire-admin');

        return $label !== $key ? $label : ucfirst(str_replace(['-', '_'], ' ', $group));
    }
}
