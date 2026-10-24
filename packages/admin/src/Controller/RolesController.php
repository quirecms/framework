<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Permission\AbilityCatalog;
use Quire\Permission\AccessExplainer;
use Quire\Permission\Exception\InvalidRoleException;
use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Quire\User\CurrentUser;
use Quire\User\Role;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Roles and what they may do (D-20): an overview, then a page per role with its abilities. Saving shows
 * first what changes, for how many users, and what deserves a second look. "Check access" explains why a
 * user may or may not do something.
 *
 * @internal
 */
final readonly class RolesController
{
    public const string ABILITY = 'quire.permissions.manage';

    private const string CSRF_TOKEN = 'quire_admin_roles';

    public function __construct(
        private Permissions $permissions,
        private SiteRoles $siteRoles,
        private AbilityCatalog $catalog,
        private AccessExplainer $explainer,
        private UserRepository $users,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        $this->assertAllowed();
        $counts = $this->users->countByRole();
        $changes = $this->permissions->changes();
        $roles = [];

        foreach ($this->permissions->roles() as $name => $label) {
            $changed = 0;

            foreach (['grant', 'revoke'] as $kind) {
                foreach ($changes[$kind] as $holders) {
                    $changed += \in_array($name, $holders, true) ? 1 : 0;
                }
            }

            $roles[] = [
                'name' => $name,
                'label' => $label,
                'owner' => $this->permissions->roleOwner($name),
                'users' => Role::GUEST === $name ? null : $counts[$name] ?? 0,
                'abilities' => \count($this->permissions->abilitiesOf($name)),
                'changed' => $changed,
            ];
        }

        return new Response($this->twig->render('@quire_admin/roles/index.html.twig', [
            'roles' => $roles,
            'ability_count' => \count($this->permissions->abilities()),
        ]));
    }

    public function show(string $role): Response
    {
        $this->assertAllowed();

        $label = $this->role($role);
        $offered = $this->offered($role);
        $has = array_values(array_intersect($this->permissions->abilitiesOf($role), $offered));
        $defaults = array_values(array_filter($offered, fn (string $name): bool => \in_array($role, $this->permissions->defaultRolesWith($name), true)));

        return new Response($this->twig->render('@quire_admin/roles/show.html.twig', [
            'role' => $role,
            'label' => $label,
            'owner' => $this->permissions->roleOwner($role),
            'site_role' => isset($this->permissions->siteRoles()[$role]),
            'users' => Role::GUEST === $role ? null : $this->users->count($role),
            'grid' => Role::ADMIN === $role ? null : $this->catalog->contentGrid(),
            'sections' => $this->catalog->sections(Role::ADMIN === $role ? $offered : null),
            'has' => $has,
            'defaults' => $defaults,
            // Everyone's abilities are every user's, whatever their roles.
            'everyone' => Role::GUEST === $role ? [] : $this->permissions->abilitiesOf(Role::GUEST),
            'changed' => [] !== array_diff($has, $defaults) || [] !== array_diff($defaults, $has),
            'problems' => $this->catalog->problems($role, $has),
        ]));
    }

    /**
     * What saving would change, to confirm.
     */
    public function review(Request $request, string $role): Response
    {
        $this->assertAllowed();
        $this->assertToken($request);
        $label = $this->role($role);
        $current = $this->permissions->abilitiesOf($role);
        $target = $request->request->getBoolean('defaults')
            ? array_values(array_filter(array_keys($this->permissions->abilities()), fn (string $name): bool => \in_array($role, $this->permissions->defaultRolesWith($name), true)))
            : $this->target($role, $request->request->all('abilities'));
        $added = array_values(array_diff($target, $current));
        $removed = array_values(array_diff($current, $target));

        if ([] === $added && [] === $removed) {
            $this->flash($request, 'success', $this->translator->trans('Nothing changed.', [], 'quire-admin'));

            return $this->redirect('quire_admin_role', ['role' => $role]);
        }

        return new Response($this->twig->render('@quire_admin/roles/review.html.twig', [
            'role' => $role,
            'label' => $label,
            'added' => array_map($this->catalog->entry(...), $added),
            'removed' => array_map($this->catalog->entry(...), $removed),
            'target' => $target,
            'version' => $this->version($current),
            'users' => Role::GUEST === $role ? null : $this->users->count($role),
            'problems' => $this->catalog->problems($role, $target),
        ]));
    }

    public function save(Request $request, string $role): RedirectResponse
    {
        $this->assertAllowed();
        $this->assertToken($request);
        $this->role($role);

        // Someone else changed the role since the review: what was confirmed isn't what would happen.
        if ($request->request->getString('version') !== $this->version($this->permissions->abilitiesOf($role))) {
            $this->flash($request, 'error', $this->translator->trans('Someone changed this role while you reviewed it. Here it is as it is now; make your changes again.', [], 'quire-admin'));

            return $this->redirect('quire_admin_role', ['role' => $role]);
        }

        $this->permissions->setAbilities($role, $this->target($role, $request->request->all('abilities')));
        $this->flash($request, 'success', $this->translator->trans('Saved what “{role}” may do.', ['role' => $this->permissions->roles()[$role]], 'quire-admin'));

        return $this->redirect('quire_admin_role', ['role' => $role]);
    }

    public function create(Request $request): RedirectResponse
    {
        $this->assertAllowed();
        $this->assertToken($request);
        $name = trim($request->request->getString('name'));
        $from = $request->request->getString('from');

        try {
            $this->siteRoles->create($name, $request->request->getString('label'), '' === $from ? null : $from);
        } catch (InvalidRoleException $e) {
            $this->flash($request, 'error', $e->getMessage());

            return $this->redirect('quire_admin_roles');
        }

        $this->flash($request, 'success', $this->translator->trans('Added the role. Choose what it may do, then give it to users.', [], 'quire-admin'));

        return $this->redirect('quire_admin_role', ['role' => $name]);
    }

    public function rename(Request $request, string $role): RedirectResponse
    {
        $this->assertAllowed();
        $this->assertToken($request);

        try {
            $this->siteRoles->relabel($role, $request->request->getString('label'));
            $this->flash($request, 'success', $this->translator->trans('Renamed.', [], 'quire-admin'));
        } catch (InvalidRoleException $e) {
            $this->flash($request, 'error', $e->getMessage());
        }

        return $this->redirect('quire_admin_role', ['role' => $role]);
    }

    public function delete(Request $request, string $role): Response
    {
        $this->assertAllowed();
        $label = $this->role($role);

        if ($request->isMethod('POST')) {
            $this->assertToken($request);

            try {
                $this->siteRoles->delete($role);
                $this->flash($request, 'success', $this->translator->trans('Deleted the role “{role}”.', ['role' => $label], 'quire-admin'));

                return $this->redirect('quire_admin_roles');
            } catch (InvalidRoleException $e) {
                $this->flash($request, 'error', $e->getMessage());

                return $this->redirect('quire_admin_role', ['role' => $role]);
            }
        }

        return new Response($this->twig->render('@quire_admin/roles/delete.html.twig', [
            'role' => $role,
            'label' => $label,
            'users' => $this->users->count($role),
            'site_role' => isset($this->permissions->siteRoles()[$role]),
        ]));
    }

    /**
     * Whether a user may do something and why; or, without a user, which roles may.
     */
    public function check(Request $request): Response
    {
        $this->assertAllowed();
        $reference = trim($request->query->getString('user'));
        $ability = $request->query->getString('ability');
        $ability = $this->permissions->has($ability) ? $ability : '';
        $user = '' === $reference ? null : $this->users->findByReference($reference);
        $explanation = null;

        if ($user instanceof User && '' !== $ability) {
            $explanation = $this->explainer->explain($user, $ability, $this->rolesOf($user));
        }

        $holders = [];

        if ('' !== $ability) {
            $counts = $this->users->countByRole();

            foreach ($this->permissions->roles() as $name => $label) {
                if ($this->permissions->allows([$name], $ability)) {
                    $holders[] = ['name' => $name, 'label' => $label, 'users' => Role::GUEST === $name ? null : $counts[$name] ?? 0];
                }
            }
        }

        return new Response($this->twig->render('@quire_admin/roles/check.html.twig', [
            'reference' => $reference,
            'user' => $user,
            'not_found' => '' !== $reference && !$user instanceof User,
            'ability' => $ability,
            'ability_label' => '' === $ability ? null : $this->catalog->label($ability),
            'abilities' => $this->abilityChoices(),
            'explanation' => $explanation,
            'holders' => $holders,
        ]));
    }

    /**
     * The abilities the role is to have: those ticked, of those the page offers. Admins have every
     * ability that isn't strict, so for them only strict ones are offered.
     *
     * @param array<mixed> $submitted
     *
     * @return list<string>
     */
    private function target(string $role, array $submitted): array
    {
        $offered = $this->offered($role);
        $ticked = array_values(array_intersect(array_filter($submitted, \is_string(...)), $offered));

        // What the page doesn't offer stays as it is.
        return array_values(array_unique([...$ticked, ...array_diff($this->permissions->abilitiesOf($role), $offered)]));
    }

    /**
     * @return list<string>
     */
    private function offered(string $role): array
    {
        $names = array_keys($this->permissions->abilities());

        return Role::ADMIN === $role
            ? array_values(array_filter($names, fn (string $name): bool => $this->permissions->abilities()[$name]['strict']))
            : $names;
    }

    /**
     * @param list<string> $abilities
     */
    private function version(array $abilities): string
    {
        sort($abilities);

        return hash('xxh128', implode(',', $abilities));
    }

    /**
     * The user's roles, with those the host application maps in.
     *
     * @return list<string>
     */
    private function rolesOf(User $user): array
    {
        $roles = $user->roles;

        foreach ($this->users->identities($user->id) as $identity) {
            if (CurrentUser::HOST_PROVIDER === $identity['provider'] && \is_array($identity['claims']['roles'] ?? null)) {
                $roles = [...$roles, ...array_filter($identity['claims']['roles'], \is_string(...))];
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return array<string, array<string, string>> Labels by name, by group
     */
    private function abilityChoices(): array
    {
        $choices = [];

        foreach ($this->catalog->contentGrid()['types'] as $type) {
            foreach ($type['abilities'] as $name) {
                $choices[$type['label']][$name] = $this->catalog->label($name);
            }
        }

        foreach ($this->catalog->sections() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['abilities'] as $entry) {
                    $choices[$section['label'].('' === $group['label'] ? '' : ' · '.$group['label'])][$entry['name']] = $entry['label'];
                }
            }
        }

        return $choices;
    }

    private function role(string $role): string
    {
        return $this->permissions->roles()[$role] ?? throw new NotFoundHttpException(\sprintf('No role "%s".', $role));
    }

    private function assertAllowed(): void
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException($this->translator->trans('Changing roles takes the right to do so.', [], 'quire-admin'));
        }
    }

    private function assertToken(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add($type, $message);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function redirect(string $route, array $parameters = []): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate($route, $parameters), Response::HTTP_SEE_OTHER);
    }
}
