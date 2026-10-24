<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Permission\AccessExplainer;
use Quire\Permission\Exception\InvalidRoleException;
use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Quire\User\CreateUser;
use Quire\User\Exception\InvalidUserException;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Roles the site adds, giving a role a set of abilities at once, explaining access (D-20), the last
 * admin, and finding users.
 */
final class RolesAndAccessTest extends KernelTestCase
{
    private Connection $connection;

    private Permissions $permissions;

    private SiteRoles $roles;

    private UserWriter $writer;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->permissions = self::getContainer()->get(Permissions::class);
        $this->roles = self::getContainer()->get(SiteRoles::class);
        $this->writer = self::getContainer()->get(UserWriter::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheSiteAddsRolesStartingFromAnother(): void
    {
        $this->roles->create('translator', 'Translator', from: 'editor');

        self::assertSame('Translator', $this->permissions->roles()['translator']);
        self::assertNull($this->permissions->roleOwner('translator'));
        self::assertSame('core', $this->permissions->roleOwner('editor'));
        self::assertSame($this->permissions->abilitiesOf('editor'), $this->permissions->abilitiesOf('translator'));
        self::assertContains('translator', $this->permissions->changes()['grant']['content.post.publish'], 'Kept as grants: it has no defaults.');

        $user = $this->user('Ana', ['translator']);
        $this->roles->relabel('translator', 'Übersetzer');
        self::assertSame('Übersetzer', $this->permissions->roles()['translator']);

        $this->roles->delete('translator');
        self::assertFalse($this->permissions->hasRole('translator'));
        self::assertSame([], $this->reload($user)->roles, 'Taken from its users.');
        self::assertNotContains('translator', $this->permissions->changes()['grant']['content.post.publish'] ?? [], 'And its grants forgotten.');
    }

    public function testRolesNeedAFreeValidName(): void
    {
        foreach ([['editor', 'Editor', null, 'There\'s a role "editor" already.'], ['Bad Name', 'X', null, 'can\'t be a role\'s name'], ['ok', '  ', null, 'Give the role a name'], ['ok', 'Ok', 'nobody', 'Unknown role "nobody"']] as [$name, $label, $from, $message]) {
            try {
                $this->roles->create($name, $label, $from);
                self::fail('Created '.$name);
            } catch (InvalidRoleException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->expectExceptionMessageIsOrContains('"editor" is declared by Quire');
        $this->roles->delete('editor');
    }

    public function testARoleGetsExactlyTheAbilitiesGiven(): void
    {
        $this->permissions->setAbilities('member', ['content.post.view_drafts', 'quire.admin.access']);

        self::assertEqualsCanonicalizing(['content.post.view_drafts', 'quire.admin.access'], $this->permissions->abilitiesOf('member'));

        // Taking abilities that are defaults revokes them; giving them back leaves no change behind.
        $editor = $this->permissions->abilitiesOf('editor');
        $this->permissions->setAbilities('editor', array_values(array_diff($editor, ['content.page.delete'])));
        self::assertSame(['editor'], $this->permissions->changes()['revoke']['content.page.delete']);
        $this->permissions->setAbilities('editor', $editor);
        self::assertArrayNotHasKey('content.page.delete', $this->permissions->changes()['revoke']);
        self::assertSame(['editor'], $this->permissions->defaultRolesWith('content.page.delete'));
    }

    public function testAccessIsExplained(): void
    {
        $explainer = self::getContainer()->get(AccessExplainer::class);
        $editor = $this->user('Ed', ['editor']);
        $admin = $this->user('Ada', ['admin']);
        $helper = $this->user('Helper', [], 'helper');

        $explanation = $explainer->explain($editor, 'content.post.publish');
        self::assertTrue($explanation->granted);
        self::assertSame(['editor'], $explanation->grantedBy);
        self::assertContains('Their role "editor" has it, by default.', $explanation->reasons);
        self::assertContains('Rules of extensions may decide differently for particular items.', $explanation->reasons);

        $this->permissions->revoke('editor', ['content.page.delete']);
        $explanation = $explainer->explain($editor, 'content.page.delete');
        self::assertFalse($explanation->granted);
        self::assertSame(['It was taken from their role "editor", which has it by default.'], \array_slice($explanation->reasons, 0, 1));

        self::assertSame(['They\'re an admin, and admins have every ability that isn\'t strict.'], \array_slice($explainer->explain($admin, 'quire.users.manage')->reasons, 0, 1));
        self::assertStringEndsWith('It\'s strict: admins have it only when granted.', $explainer->explain($admin, 'quire-fixture-hello.purge')->reasons[0]);
        self::assertTrue($explainer->explain($editor, 'content.post.view')->granted, 'Everyone may view posts.');
        self::assertStringStartsWith('Everyone has it', $explainer->explain($editor, 'content.post.view')->reasons[0]);

        $explanation = $explainer->explain($helper, 'quire-fixture-hello.moderate');
        self::assertTrue($explanation->granted, 'The fixture\'s rule lets "helper" moderate.');
        self::assertStringStartsWith('A rule of quire-fixture-hello grants it', $explanation->reasons[0]);

        self::assertStringContainsString('Only for items they wrote.', implode(' ', $explainer->explain($editor, 'content.post.edit_own')->reasons));
        $this->writer->suspend($editor->id);
        self::assertFalse($explainer->explain($this->reload($editor), 'content.post.publish')->granted);
    }

    public function testTheSiteKeepsAnActiveAdmin(): void
    {
        $this->connection->executeStatement("UPDATE quire.app_user SET status = 'suspended' WHERE 'admin' = ANY(roles)");
        $first = $this->user('Ada', ['admin']);

        foreach (['take the admin role from' => fn (): User => $this->writer->setRoles($first->id, ['editor']), 'suspend' => fn (): User => $this->writer->suspend($first->id), 'delete' => fn () => $this->writer->delete($first->id)] as $action => $attempt) {
            try {
                $attempt();
                self::fail('Allowed to '.$action.' the last admin.');
            } catch (InvalidUserException $e) {
                self::assertSame(['roles' => \sprintf('You can\'t %s the last active admin: the site needs one. Make another user an admin first.', $action)], $e->violations);
            }
        }

        // With a second admin, either can go.
        $second = $this->user('Grace', ['admin', 'editor']);
        $this->writer->setRoles($first->id, ['editor']);
        self::assertSame(['editor'], $this->reload($first)->roles);
        $this->expectException(InvalidUserException::class);
        $this->writer->setRoles($second->id, ['editor']);
    }

    public function testUsersAreFoundAPageAtATime(): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        $this->user('Zoe Zed', ['editor']);
        $this->user('Adam Zed', ['member']);
        $suspended = $this->user('Bea Zed', ['editor']);
        $this->writer->suspend($suspended->id);

        $found = $users->search('zed', limit: 2);
        self::assertSame(3, $found['total']);
        self::assertSame(['Adam Zed', 'Bea Zed'], array_map(static fn (User $user): string => $user->displayName, $found['users']));
        self::assertSame(['Zoe Zed'], array_map(static fn (User $user): string => $user->displayName, $users->search('zed', limit: 2, offset: 2)['users']));
        self::assertSame(3, $users->search('zed', limit: 2, offset: 10)['total'], 'Past the last page too.');
        self::assertSame(['Zoe Zed'], array_map(static fn (User $user): string => $user->displayName, $users->search('zed', 'editor', User::ACTIVE)['users']));
        self::assertSame('Zoe Zed', $users->search('zed', sort: 'name', descending: true)['users'][0]->displayName);
        self::assertSame(0, $users->search('100%')['total'], 'Wildcards are text.');
        self::assertGreaterThanOrEqual(2, $users->countByRole()['editor']);
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $name, array $roles, ?string $username = null): User
    {
        return $this->writer->create(new CreateUser($name, strtolower(str_replace(' ', '.', $name)).'@example.com', username: $username, roles: $roles));
    }

    private function reload(User $user): User
    {
        return self::getContainer()->get(UserRepository::class)->find($user->id) ?? throw new \LogicException('Gone.');
    }
}
