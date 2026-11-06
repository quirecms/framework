<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Action\Actions;
use Quire\Permission\Delegation;
use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Quire\Settings\SettingsTransfer;
use Quire\Theme\Themes;
use Quire\User\CreateUser;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Nobody gives out, or acts beyond, what they hold (D-42): the audit's escalations (AUTHZ-01, -02, -03),
 * through core's actions and the settings import, as the intent bar, undo and the admin use them.
 */
final class DelegationTest extends KernelTestCase
{
    private Connection $connection;

    private Actions $actions;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->actions = self::getContainer()->get(Actions::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testWhatSomeoneHoldsIsWhatTheyMayGive(): void
    {
        $delegation = self::getContainer()->get(Delegation::class);
        $hr = $this->delegate('hr', ['quire.users.manage']);
        $admin = $this->user('Ada', ['admin']);
        $member = $this->user('Mo', ['member']);

        self::assertSame(['quire.extensions.manage'], $delegation->missing($hr, ['quire.users.manage', 'quire.extensions.manage']));
        self::assertArrayHasKey('admin', $delegation->rolesBeyond($hr, ['admin', 'member']));
        self::assertArrayNotHasKey('member', $delegation->rolesBeyond($hr, ['admin', 'member']));
        self::assertNotEmpty($delegation->beyond($hr, $admin), 'An admin outranks them.');
        self::assertSame([], $delegation->beyond($hr, $member));
        self::assertSame([], $delegation->beyond($admin, $this->user('Noor', ['admin'])), 'Admins manage admins.');
    }

    public function testAUserManagerDoesntMakeThemselvesAdminOrTakeOverAnAdmin(): void
    {
        // AUTHZ-01
        $hr = $this->delegate('hr', ['quire.users.manage']);
        $admin = $this->user('Ada', ['admin']);
        $member = $this->user('Mo', ['member']);
        $this->signIn($hr);

        self::assertStringStartsWith('You can\'t give the role "admin"', (string) $this->actions->denied('user.change_roles', ['user' => $hr->username, 'add' => ['admin']]));
        self::assertStringStartsWith('You can\'t manage Ada', (string) $this->actions->denied('user.update', ['user' => $admin->username, 'email' => 'attacker@example.net']));
        self::assertStringStartsWith('You can\'t manage Ada', (string) $this->actions->denied('user.suspend', ['user' => $admin->username]));
        self::assertStringStartsWith('You can\'t manage Ada', (string) $this->actions->denied('user.remove_password', ['user' => $admin->username]));
        self::assertStringStartsWith('You can\'t give the role "admin"', (string) $this->actions->denied('user.invite', ['users' => ['friend@example.net'], 'roles' => ['admin']]));
        self::assertStringStartsWith('You can\'t give the role "admin"', (string) $this->actions->denied('user.create', ['email' => 'friend@example.net', 'roles' => ['admin']]));
        self::assertStringStartsWith('Passwords aren\'t set for others here', (string) $this->actions->denied('user.set_password', ['user' => $member->username, 'password' => 'correct-horse-42']));
        self::assertStringStartsWith('Passwords aren\'t set for others here', (string) $this->actions->denied('user.create', ['email' => 'friend@example.net', 'password' => 'correct-horse-42']));

        // Within what they hold, they manage
        self::assertNull($this->actions->denied('user.change_roles', ['user' => $member->username, 'add' => ['member']]));
        self::assertNull($this->actions->denied('user.suspend', ['user' => $member->username]));
    }

    public function testAPermissionManagerGrantsOnlyWhatTheyHold(): void
    {
        // AUTHZ-03
        $manager = $this->delegate('perms', ['quire.permissions.manage']);
        $this->signIn($manager);

        self::assertStringStartsWith('You can\'t give or take away "', (string) $this->actions->denied('role.grant', ['role' => 'perms', 'abilities' => ['quire.extensions.manage']]));
        self::assertStringStartsWith('You can\'t give or take away "', (string) $this->actions->denied('role.revoke', ['role' => 'editor', 'abilities' => ['content.post.publish']]));
        self::assertStringStartsWith('You can\'t give the role "admin"', (string) $this->actions->denied('role.create', ['role' => 'twin', 'label' => 'Twin', 'from' => 'admin']));
        self::assertStringStartsWith('You can\'t give the role "editor"', (string) $this->actions->denied('role.delete', ['role' => 'editor']));
        self::assertNull($this->actions->denied('role.grant', ['role' => 'editor', 'abilities' => ['quire.permissions.manage']]), 'What they hold, they give.');
    }

    public function testManagingSettingsIsntAWayAroundTheRest(): void
    {
        // AUTHZ-02
        $manager = $this->delegate('settings', ['quire.settings.manage']);
        $this->signIn($manager);
        $permissions = self::getContainer()->get(Permissions::class);
        $grants = ['grant' => ['quire.extensions.manage' => ['settings'], 'quire.users.manage' => ['settings']]];

        self::assertStringStartsWith('Changing it takes the right "', (string) $this->actions->denied('setting.set', ['setting' => Permissions::SETTING, 'value' => $grants]));
        self::assertStringStartsWith('Changing it takes the right "', (string) $this->actions->denied('setting.set', ['setting' => Permissions::SETTING, 'default' => true]));
        self::assertStringStartsWith('Changing it takes the right "', (string) $this->actions->denied('setting.set', ['setting' => Themes::SETTING, 'value' => 'other']));
        self::assertNull($this->actions->denied('setting.set', ['setting' => 'site.title', 'value' => 'Fine']));

        $transfer = self::getContainer()->get(SettingsTransfer::class);
        $import = $transfer->plan([Permissions::SETTING => $grants, 'site.title' => 'Mine now'], by: $manager);
        self::assertFalse($import->canApply(), 'All or nothing.');
        self::assertStringStartsWith(Permissions::SETTING.': Changing it takes the right', $import->errors[0] ?? '');
        self::assertTrue($transfer->plan([Permissions::SETTING => $grants])->canApply(), 'The command line runs the site.');
        self::assertNotContains('settings', $permissions->rolesWith('quire.extensions.manage'));

        // Who manages permissions too still gives only what they hold
        $both = $this->delegate('both', ['quire.settings.manage', 'quire.permissions.manage']);
        $this->signIn($both);
        self::assertStringStartsWith('It gives or takes away "', (string) $this->actions->denied('setting.set', ['setting' => Permissions::SETTING, 'value' => ['grant' => ['quire.extensions.manage' => ['both']]]]));
        self::assertNull($this->actions->denied('setting.set', ['setting' => Permissions::SETTING, 'value' => ['grant' => ['quire.settings.manage' => ['editor']]]]));
    }

    /**
     * A role of the site with admin access and the given abilities, and a user with it.
     *
     * @param list<string> $abilities
     */
    private function delegate(string $role, array $abilities): User
    {
        self::getContainer()->get(SiteRoles::class)->create($role, ucfirst($role));
        self::getContainer()->get(Permissions::class)->grant($role, ['quire.admin.access', ...$abilities]);

        return $this->user(ucfirst($role), [$role]);
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $name, array $roles): User
    {
        return self::getContainer()->get(UserWriter::class)->create(new CreateUser($name, strtolower($name).'-'.bin2hex(random_bytes(3)).'@example.com', strtolower($name).bin2hex(random_bytes(2)), roles: $roles));
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
