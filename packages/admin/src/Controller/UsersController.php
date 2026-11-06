<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Action\Actions;
use Quire\Action\Exception\ActionDeniedException;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Admin\Permission\AbilityCatalog;
use Quire\Admin\Security\ConfirmGuard;
use Quire\Permission\Access;
use Quire\Permission\Delegation;
use Quire\Permission\Permissions;
use Quire\Permission\ViewAs;
use Quire\Routing\AdminAccessListener;
use Quire\Settings\Settings;
use Quire\User\CreateUser;
use Quire\User\CurrentUser;
use Quire\User\Exception\InvalidUserException;
use Quire\User\Exception\UserNotFoundException;
use Quire\User\Invitation;
use Quire\User\Invitations;
use Quire\User\Mail\UserMailer;
use Quire\User\Role;
use Quire\User\UpdateUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The site's users: a list to search, adding them (invited by email to choose their password), changing
 * them, suspending and deleting them, through the same services as the quire:user:* commands.
 *
 * @internal
 */
final readonly class UsersController
{
    public const string ABILITY = 'quire.users.manage';

    private const string CSRF_TOKEN = 'quire_admin_users';

    private const int PER_PAGE = 50;

    private const array SORTS = ['name', 'username', 'created', 'login'];

    /** How long people who joined are marked "new" */
    private const string NEW_FOR = '-7 days';

    public function __construct(
        private UserRepository $users,
        private UserWriter $writer,
        private Invitations $invitations,
        private Permissions $permissions,
        private CurrentUser $currentUser,
        private Settings $settings,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
        private Actions $actions,
        private AbilityCatalog $abilities,
        private ViewAs $viewAs,
        private Access $access,
        private Delegation $delegation,
        private ConfirmGuard $confirm,
        private UserMailer $mailer,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->assertAllowed();
        $query = $request->query;
        $roles = $this->assignableRoles();
        $view = $query->getString('view');
        $view = \in_array($view, ['invited', 'suspended'], true) || (str_starts_with($view, 'role:') && isset($roles[substr($view, 5)])) ? $view : '';
        $state = [
            'view' => $view,
            'q' => trim($query->getString('q')),
            'sort' => \in_array(ltrim($query->getString('sort'), '-'), self::SORTS, true) ? $query->getString('sort') : 'name',
        ];
        $page = max(1, $query->getInt('page', 1));
        $sort = ltrim($state['sort'], '-');
        $result = $this->users->search(
            '' === $state['q'] ? null : $state['q'],
            str_starts_with($view, 'role:') ? substr($view, 5) : null,
            'suspended' === $view ? User::SUSPENDED : null,
            $sort,
            str_starts_with($state['sort'], '-'),
            self::PER_PAGE,
            ($page - 1) * self::PER_PAGE,
            'invited' === $view ? true : null,
        );
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));
        $url = fn (array $changes): string => $this->urls->generate('quire_admin_users', array_filter([...$state, 'page' => null, ...$changes], static fn (mixed $value): bool => null !== $value && '' !== $value && 'name' !== $value && 1 !== $value));
        $pending = $this->invitations->pending(array_map(static fn (User $user): string => $user->id, $result['users']));
        $byRole = $this->users->countByRole();
        $everyone = $this->users->search(limit: 1)['total'];
        $tabs = [['view' => '', 'label' => $this->translator->trans('Everyone', [], 'quire-admin'), 'count' => $everyone]];

        foreach ($roles as $role => $label) {
            if (($byRole[$role] ?? 0) > 0) {
                $tabs[] = ['view' => 'role:'.$role, 'label' => $this->translator->trans($label, [], $this->permissions->roleOwner($role) ?? 'quire-admin'), 'count' => $byRole[$role]];
            }
        }

        $tabs[] = ['view' => 'invited', 'label' => $this->translator->trans('Invited, not joined', [], 'quire-admin'), 'count' => \count($this->invitations->pending()), 'attention' => true];
        $tabs[] = ['view' => 'suspended', 'label' => $this->translator->trans('users.status.suspended', [], 'quire-admin'), 'count' => $this->users->search(status: User::SUSPENDED, limit: 1)['total']];
        $current = array_find($tabs, static fn (array $tab): bool => $tab['view'] === $view) ?? $tabs[0];

        return new Response($this->twig->render('@quire_admin/users/list.html.twig', [
            'users' => array_map(fn (User $user): array => $this->row($user, $pending[$user->id] ?? null), $result['users']),
            'total' => $result['total'],
            'state' => $state,
            'tabs' => array_map(static fn (array $tab): array => [...$tab, 'url' => $url(['view' => $tab['view'], 'sort' => $state['sort']])], array_values(array_filter($tabs, static fn (array $tab): bool => '' === $tab['view'] || $tab['count'] > 0 || $tab['view'] === $view))),
            'current' => $current,
            'roles' => $roles,
            'sentences' => array_map($this->abilities->sentence(...), array_combine(array_keys($roles), array_keys($roles))),
            'invitation_problem' => $this->invitations->unavailableReason(),
            'columns' => array_map(static fn (string $column): array => [
                'id' => $column,
                'sorted' => $sort === $column ? (str_starts_with($state['sort'], '-') ? 'desc' : 'asc') : null,
                'sort_url' => $url(['sort' => $sort === $column && !str_starts_with($state['sort'], '-') ? '-'.$column : $column]),
            ], ['name', 'login']),
            'page' => $page,
            'pages' => $pages,
            'previous_url' => $page > 1 ? $url(['page' => $page - 1]) : null,
            'next_url' => $page < $pages ? $url(['page' => $page + 1]) : null,
            'back' => $request->getRequestUri(),
        ]));
    }

    /**
     * Invites people by email, as the role chosen: each one is added, then invited.
     */
    public function inviteMany(Request $request): RedirectResponse
    {
        $this->assertAllowed();
        $this->assertToken($request);
        $parts = preg_split('/[\s,;]+/u', $request->request->getString('emails'));
        $emails = array_values(array_unique(array_filter(false === $parts ? [] : $parts, static fn (string $email): bool => '' !== $email)));
        $role = $request->request->getString('role');
        $roles = isset($this->assignableRoles()[$role]) ? [$role] : [];
        [$invited, $problems] = [0, []];
        $beyond = $this->rolesBeyond($roles);

        if ([] !== $beyond) {
            $this->flash($request, 'error', $this->rolesRefusal($beyond));

            return $this->redirect('quire_admin_users');
        }

        $confirm = $this->confirm->required($request, $this->urls->generate('quire_admin_users'));

        if ($confirm instanceof RedirectResponse) {
            return $confirm;
        }

        foreach ($emails as $email) {
            try {
                if (!str_contains($email, '@')) {
                    throw new \InvalidArgumentException($this->translator->trans('That isn\'t an email.', [], 'quire-admin'));
                }

                $this->actions->run('user.invite', ['users' => [$email], 'roles' => $roles]);
                ++$invited;
            } catch (ActionDeniedException $e) {
                throw new AccessDeniedHttpException($e->getMessage(), $e);
            } catch (InvalidUserException $e) {
                $problems[] = $email.': '.implode(' ', $e->violations);
            } catch (InvalidArgumentsException $e) {
                $problems[] = $email.': '.implode(' ', $e->errors);
            } catch (TransportExceptionInterface|\InvalidArgumentException|\LogicException $e) {
                $problems[] = $email.': '.$e->getMessage();
            }
        }

        if ([] === $emails) {
            $this->flash($request, 'error', $this->translator->trans('Enter the emails of the people to invite.', [], 'quire-admin'));
        }

        if ($invited > 0) {
            $this->flash($request, 'success', $this->translator->trans('{count, plural, one {Invited # person} other {Invited # people}} by email to choose a password. The link works for seven days.', ['count' => $invited], 'quire-admin'));
        }

        if ([] !== $problems) {
            $this->flash($request, 'error', $this->translator->trans('These couldn\'t be invited: {problems}', ['problems' => implode('; ', $problems)], 'quire-admin'));
        }

        return $this->redirect('quire_admin_users', $invited > 0 ? ['view' => 'invited'] : []);
    }

    /**
     * A user as the list shows them: their status, and the one thing to do about it.
     *
     * @return array{user: User, status: string, new: bool, invitation: ?Invitation}
     */
    private function row(User $user, ?Invitation $invitation): array
    {
        $status = match (true) {
            !$user->isActive() => 'suspended',
            $invitation instanceof Invitation => $invitation->isExpired() ? 'expired' : 'invited',
            default => 'active',
        };

        return ['user' => $user, 'status' => $status, 'new' => 'active' === $status && $user->createdAt > new \DateTimeImmutable(self::NEW_FOR), 'invitation' => $invitation];
    }

    public function create(Request $request): Response
    {
        $this->assertAllowed();
        $values = ['display_name' => '', 'email' => '', 'username' => '', 'roles' => [], 'password_mode' => null === $this->invitations->unavailableReason() ? 'invite' : 'none'];
        $confirm = $this->confirm->required($request, $this->urls->generate('quire_admin_user_new'));

        if ($confirm instanceof RedirectResponse) {
            return $confirm;
        }

        if (!$request->isMethod('POST')) {
            return $this->form(null, $values, []);
        }

        $this->assertToken($request);
        $values = $this->submitted($request) + $values;
        // Nobody chooses another person's password (D-42): they're invited to choose it
        $values['password_mode'] = 'invite' === $values['password_mode'] ? 'invite' : 'none';
        $beyond = $this->rolesBeyond($values['roles']);

        if ([] !== $beyond) {
            return $this->form(null, $values, ['roles' => $this->rolesRefusal($beyond)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ('' === trim($values['email']) && 'invite' === $values['password_mode']) {
            return $this->form(null, $values, ['email' => $this->translator->trans('An invitation goes to the user\'s email, so enter one.', [], 'quire-admin')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $user = $this->writer->create(new CreateUser(
                displayName: $values['display_name'],
                email: '' === trim($values['email']) ? null : $values['email'],
                username: '' === trim($values['username']) ? null : $values['username'],
                roles: $values['roles'],
            ));
        } catch (InvalidUserException $e) {
            return $this->form(null, $values, $e->violations, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->flash($request, 'success', $this->translator->trans('Added {name}.', ['name' => $user->displayName], 'quire-admin'));

        if ('invite' === $values['password_mode']) {
            $this->invite($request, $user);
        }

        return $this->redirect('quire_admin_user', ['id' => $user->id]);
    }

    public function edit(Request $request, string $id): Response
    {
        $this->assertAllowed();
        $user = $this->user($id);
        $values = ['display_name' => $user->displayName, 'email' => (string) $user->email, 'username' => $user->username, 'roles' => $user->roles];
        $self = $this->isSelf($user);
        // Nobody manages someone who can do things they can't (D-42)
        $locked = $self ? null : $this->outranks($user);

        // Someone else's account, or one's own sent: confirmed first, so an open session isn't enough
        if (null === $locked && (!$self || $request->isMethod('POST'))) {
            $confirm = $this->confirm->required($request, $this->urls->generate('quire_admin_user', ['id' => $user->id]));

            if ($confirm instanceof RedirectResponse) {
                return $confirm;
            }
        }

        if (!$request->isMethod('POST')) {
            return $this->form($user, $values, [], locked: $locked);
        }

        if (null !== $locked) {
            throw new AccessDeniedHttpException($locked);
        }

        $this->assertToken($request);
        $values = $this->submitted($request) + $values;
        $password = $request->request->getString('password');

        // Roles the user has but the form doesn't offer, e.g. of a disabled extension, stay; so do those
        // the person signed in can't give or take away.
        $changeable = array_diff(array_keys($this->assignableRoles()), array_keys($this->rolesBeyond(array_keys($this->assignableRoles()))));
        $roles = [...array_diff($user->roles, $changeable), ...array_intersect($values['roles'], $changeable)];

        if ('' !== $password && !$self) {
            return $this->form($user, $values, ['password' => $this->translator->trans('Passwords aren\'t set for others: email them a link to choose a new one.', [], 'quire-admin')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $after = $this->writer->update(new UpdateUser($user->id)
                ->displayName($values['display_name'])
                ->email('' === trim($values['email']) ? null : $values['email'])
                ->username($values['username']));

            if ($this->sorted($roles) !== $this->sorted($user->roles)) {
                $this->writer->setRoles($user->id, $roles);
            }

            if ('' !== $password) {
                $this->writer->changePassword($user->id, $password);
            }
        } catch (InvalidUserException $e) {
            return $this->form($user, $values, $e->violations, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->flash($request, 'success', $this->translator->trans('Saved.', [], 'quire-admin'));

        // The owner of the old address learns of it, even when the new one is someone else's
        if (!$self && null !== $user->email && $after->email !== $user->email) {
            try {
                $this->mailer->sendEmailChanged($user, (string) $after->email, $this->currentUser->get());
            } catch (TransportExceptionInterface|\LogicException $e) {
                $this->flash($request, 'error', $this->translator->trans('{name} couldn\'t be told at their old address: {problem}', ['name' => $user->displayName, 'problem' => $e->getMessage()], 'quire-admin'));
            }
        }

        return $this->redirect('quire_admin_user', ['id' => $user->id]);
    }

    /**
     * Suspends or activates the user, or invites them again.
     */
    public function act(Request $request, string $id, string $action): RedirectResponse
    {
        $this->assertAllowed();
        $this->assertToken($request);
        $user = $this->user($id);
        $refusal = $this->isSelf($user) ? null : $this->outranks($user);

        if (null !== $refusal) {
            throw new AccessDeniedHttpException($refusal);
        }

        $confirm = $this->confirm->required($request, $this->urls->generate('quire_admin_user', ['id' => $user->id]));

        if ($confirm instanceof RedirectResponse) {
            return $confirm;
        }

        if ('invite' === $action) {
            null === $user->passwordHash ? $this->invite($request, $user) : $this->askForNewPassword($request, $user);

            return $this->back($request) ?? $this->redirect('quire_admin_user', ['id' => $user->id]);
        }

        if ($this->isSelf($user)) {
            throw new AccessDeniedHttpException($this->translator->trans('You can\'t suspend yourself. Ask another admin.', [], 'quire-admin'));
        }

        try {
            'suspend' === $action ? $this->writer->suspend($user->id) : $this->writer->activate($user->id);
            $this->flash($request, 'success', $this->translator->trans('suspend' === $action ? '{name} is suspended: they can\'t sign in.' : '{name} is active again.', ['name' => $user->displayName], 'quire-admin'));
        } catch (InvalidUserException $e) {
            $this->flash($request, 'error', implode(' ', $e->violations));
        }

        return $this->back($request) ?? $this->redirect('quire_admin_user', ['id' => $user->id]);
    }

    /**
     * Back to the page of the admin a form came from, e.g. the list.
     */
    private function back(Request $request): ?RedirectResponse
    {
        $back = $request->request->getString('back');
        $admin = rtrim($this->urls->generate('quire_admin_dashboard'), '/');

        return str_starts_with($back, $admin.'/') && !str_starts_with($back, '//') ? new RedirectResponse($back, Response::HTTP_SEE_OTHER) : null;
    }

    public function delete(Request $request, string $id): Response
    {
        $this->assertAllowed();
        $user = $this->user($id);

        if ($this->isSelf($user)) {
            throw new AccessDeniedHttpException($this->translator->trans('You can\'t delete yourself. Ask another admin.', [], 'quire-admin'));
        }

        $refusal = $this->outranks($user);

        if (null !== $refusal) {
            throw new AccessDeniedHttpException($refusal);
        }

        $confirm = $this->confirm->required($request, $this->urls->generate('quire_admin_user_delete', ['id' => $user->id]));

        if ($confirm instanceof RedirectResponse) {
            return $confirm;
        }

        $count = $this->writer->contentCount($user->id);
        $heirs = array_values(array_filter($this->users->search(limit: 200)['users'], static fn (User $other): bool => $other->id !== $user->id));
        $error = null;

        if ($request->isMethod('POST')) {
            $this->assertToken($request);
            $heir = $request->request->getString('reassign');

            try {
                $this->writer->delete($user->id, '' === $heir ? null : $heir);
                $this->flash($request, 'success', $this->translator->trans('Deleted {name}.', ['name' => $user->displayName], 'quire-admin'));

                return $this->redirect('quire_admin_users');
            } catch (InvalidUserException $e) {
                $error = implode(' ', $e->violations);
            } catch (UserNotFoundException) {
                $error = $this->translator->trans('Choose a user from the list.', [], 'quire-admin');
            }
        }

        return new Response($this->twig->render('@quire_admin/users/delete.html.twig', [
            'user' => $user,
            'content_count' => $count,
            'heirs' => $heirs,
            'error' => $error,
        ]), null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array{display_name: string, email: string, username: string, roles: list<string>, password_mode?: string} $values
     * @param array<string, string>                                                                                     $errors
     */
    private function form(?User $user, array $values, array $errors, int $status = Response::HTTP_OK, ?string $locked = null): Response
    {
        $identities = $user instanceof User ? $this->users->identities($user->id) : [];
        $hostRoles = [];

        foreach ($identities as $identity) {
            if (CurrentUser::HOST_PROVIDER === $identity['provider'] && \is_array($identity['claims']['roles'] ?? null)) {
                $hostRoles = array_values(array_filter($identity['claims']['roles'], \is_string(...)));
            }
        }

        return new Response($this->twig->render('@quire_admin/users/form.html.twig', [
            'user' => $user,
            'values' => $values,
            'errors' => $errors,
            'roles' => $this->assignableRoles(),
            'host_roles' => $hostRoles,
            'identities' => $identities,
            'content_count' => $user instanceof User ? $this->writer->contentCount($user->id) : 0,
            'invitation_problem' => $this->invitations->unavailableReason(),
            'self' => $user instanceof User && $this->isSelf($user),
            'view_as_refusal' => $user instanceof User ? $this->viewAs->refusal($user) : null,
            'view_as_admin' => $user instanceof User && $this->access->for($user)->allows(AdminAccessListener::ABILITY),
            // Why the person can't manage them, or null; and the roles they can't give or take away (D-42)
            'locked' => $locked,
            'roles_beyond' => array_keys($this->rolesBeyond(array_keys($this->assignableRoles()))),
            'timezone' => $this->settings->getString('site.timezone'),
        ]), $status);
    }

    /**
     * @return array{display_name: string, email: string, username: string, roles: list<string>, password_mode: string}
     */
    private function submitted(Request $request): array
    {
        $roles = array_values(array_filter($request->request->all('roles'), \is_string(...)));

        return [
            'display_name' => trim($request->request->getString('display_name')),
            'email' => trim($request->request->getString('email')),
            'username' => trim($request->request->getString('username')),
            'roles' => array_values(array_intersect($roles, array_keys($this->assignableRoles()))),
            'password_mode' => \in_array($request->request->getString('password_mode'), ['invite', 'set', 'none'], true) ? $request->request->getString('password_mode') : 'none',
        ];
    }

    private function invite(Request $request, User $user): void
    {
        try {
            $this->invitations->invite($user, $this->currentUser->get());
            $this->flash($request, 'success', $this->translator->trans('Invited {name} by email to choose a password. The link works for seven days.', ['name' => $user->displayName], 'quire-admin'));
        } catch (InvalidUserException $e) {
            $this->flash($request, 'error', implode(' ', $e->violations));
        } catch (TransportExceptionInterface|\LogicException $e) {
            $this->flash($request, 'error', $this->translator->trans('The invitation couldn\'t be sent: {problem}', ['problem' => $e->getMessage()], 'quire-admin'));
        }
    }

    private function askForNewPassword(Request $request, User $user): void
    {
        try {
            $this->invitations->askForNewPassword($user, $this->currentUser->get());
            $this->flash($request, 'success', $this->translator->trans('Emailed {name} a link to choose a new password. It works for a day; until then, their password stays as it is.', ['name' => $user->displayName], 'quire-admin'));
        } catch (InvalidUserException $e) {
            $this->flash($request, 'error', implode(' ', $e->violations));
        } catch (TransportExceptionInterface|\LogicException $e) {
            $this->flash($request, 'error', $this->translator->trans('The link couldn\'t be sent: {problem}', ['problem' => $e->getMessage()], 'quire-admin'));
        }
    }

    /**
     * Why the person signed in may not manage the user, or null: they can do things the person can't.
     */
    private function outranks(User $user): ?string
    {
        $actor = $this->security->getUser();
        $beyond = $actor instanceof \Symfony\Component\Security\Core\User\UserInterface ? $this->delegation->beyond($actor, $user) : ['quire.admin.access'];

        return [] === $beyond ? null : $this->translator->trans('You can\'t manage {name}: they can do things you can\'t ({abilities}).', ['name' => $user->displayName, 'abilities' => $this->delegation->labels($beyond)], 'quire-admin');
    }

    /**
     * Of the roles, those the person signed in can't give, with what they lack.
     *
     * @param list<string> $roles
     *
     * @return array<string, list<string>>
     */
    private function rolesBeyond(array $roles): array
    {
        $actor = $this->security->getUser();

        return $actor instanceof \Symfony\Component\Security\Core\User\UserInterface ? $this->delegation->rolesBeyond($actor, $roles) : array_fill_keys($roles, ['quire.admin.access']);
    }

    /**
     * @param array<string, list<string>> $beyond
     */
    private function rolesRefusal(array $beyond): string
    {
        $role = (string) array_key_first($beyond);

        return $this->translator->trans('You can\'t give the role “{role}”: it can do things you can\'t ({abilities}).', ['role' => $this->assignableRoles()[$role] ?? $role, 'abilities' => $this->delegation->labels($beyond[$role])], 'quire-admin');
    }

    /**
     * The roles users can be given: all but "Everyone".
     *
     * @return array<string, string> Name => label
     */
    private function assignableRoles(): array
    {
        return array_diff_key($this->permissions->roles(), [Role::GUEST => true]);
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function sorted(array $roles): array
    {
        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }

    private function isSelf(User $user): bool
    {
        return $this->currentUser->get()?->id === $user->id;
    }

    private function user(string $id): User
    {
        return $this->users->find($id) ?? throw new NotFoundHttpException(\sprintf('No user "%s".', $id));
    }

    private function assertAllowed(): void
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException($this->translator->trans('Managing users takes the right to do so.', [], 'quire-admin'));
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
