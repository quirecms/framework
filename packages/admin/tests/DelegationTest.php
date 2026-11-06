<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Quire\Testing\AdminTestCase;
use Quire\Theme\Themes;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Mime\Email;

/**
 * Nobody hands out, or acts beyond, what they hold, and what changes who may do what is confirmed
 * (D-42): the audit's escalations through the admin's pages (AUTHZ-01, -02, -03), each refused now.
 */
final class DelegationTest extends AdminTestCase
{
    public function testAUserManagerDoesntMakeThemselvesAdmin(): void
    {
        $hr = $this->signIn($this->delegate('hr', ['quire.users.manage']));
        $crawler = $this->client->request('GET', '/admin/users/'.$hr->id);

        self::assertNotNull($crawler->filter('#role-admin')->attr('disabled'), 'Not theirs to give.');
        $form = $crawler->selectButton('Save')->form();
        $this->client->request('POST', '/admin/users/'.$hr->id, ['_token' => $form->getValues()['_token'], 'display_name' => 'Hr', 'email' => (string) $hr->email, 'username' => $hr->username, 'roles' => ['hr', 'admin']]);

        self::assertResponseRedirects();
        self::assertSame(['hr'], $this->reload($hr)->roles, 'The admin role sent anyway isn\'t given.');
    }

    public function testAUserManagerDoesntTakeOverAnAdmin(): void
    {
        $admin = $this->createUser('Ada', ['admin']);
        $this->signIn($this->delegate('hr', ['quire.users.manage']));
        $crawler = $this->client->request('GET', '/admin/users/'.$admin->id);

        self::assertSelectorTextContains('.q-notice--warning', 'You can\'t manage Ada: they can do things you can\'t');
        self::assertNotNull($crawler->filter('form.settings-form fieldset.q-plain')->attr('disabled'));
        self::assertCount(0, $crawler->filter('button:contains("Suspend"), button:contains("Email a link")'));

        $this->client->request('POST', '/admin/users/'.$admin->id, ['_token' => $this->csrfToken('quire_admin_users'), 'display_name' => 'Ada', 'email' => 'attacker@example.net', 'username' => $admin->username, 'roles' => ['admin']]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/users/'.$admin->id.'/suspend', ['_token' => $this->csrfToken('quire_admin_users')]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([(string) $admin->email, true], [(string) $this->reload($admin)->email, $this->reload($admin)->isActive()]);
    }

    public function testAPermissionManagerGrantsOnlyWhatTheyHold(): void
    {
        $this->signIn($this->delegate('perms', ['quire.permissions.manage']));
        $crawler = $this->client->request('GET', '/admin/roles/perms');

        self::assertNotNull($crawler->filter('#ability-quire-extensions-manage')->attr('disabled'));
        self::assertStringContainsString('You don\'t have this', $crawler->filter('#ability-quire-extensions-manage-locked')->text());

        // Sent anyway, they're kept as they are
        $form = $crawler->filter('form[action$="/review"]')->form();
        $this->client->request('POST', '/admin/roles/perms/review', ['_token' => $form->getValues()['_token'], 'abilities' => ['quire.admin.access', 'quire.permissions.manage', 'quire.extensions.manage', 'quire.users.manage']]);
        self::assertResponseRedirects('/admin/roles/perms');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Nothing changed.');
        self::assertNotContains('perms', $this->service(Permissions::class)->rolesWith('quire.extensions.manage'));
    }

    public function testASettingsManagerCantImportPermissionsOrATheme(): void
    {
        $this->signIn($this->delegate('settings', ['quire.settings.manage']));
        $crawler = $this->client->request('GET', '/admin/settings');
        $token = (string) $crawler->filter('.settings-move__import input[name=_token]')->attr('value');
        $file = Permissions::SETTING.":\n  grant:\n    quire.users.manage: [settings]\n".Themes::SETTING.": other\nsite.title: Mine now\n";

        $crawler = $this->client->request('POST', '/admin/settings/import', ['_token' => $token, 'content' => $file]);
        self::assertStringContainsString(Permissions::SETTING.': Changing it takes the right', $crawler->filter('.q-error-summary, .q-notice--error, [role=alert]')->text());
        self::assertCount(0, $crawler->filter('button:contains("Change them")'), 'All or nothing.');

        $this->client->request('POST', '/admin/settings/import', ['_token' => $token, 'content' => $file, 'apply' => '1']);
        self::assertNotContains('settings', $this->service(Permissions::class)->rolesWith('quire.users.manage'));
        self::assertNotSame('Mine now', $this->service(\Quire\Settings\Settings::class)->getString('site.title'));

        // Nor reset them to their defaults through a group's form
        $this->client->request('POST', '/admin/settings/site', ['_token' => $token, 'default' => Permissions::SETTING]);
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('.q-notice--error', 'That setting isn\'t on this page.');
    }

    public function testAnAdminStillManagesAdminsOnceTheyConfirmItsThem(): void
    {
        $noor = $this->createUser('Noor', ['admin']);
        $ada = $this->signIn($this->createUser('Ada', ['admin']));
        $this->confirmedAgo(11 * 60);

        // Confirm it's you, then back to the page
        $this->client->request('GET', '/admin/users/'.$noor->id);
        self::assertStringEndsWith('/admin/confirm?back=/admin/users/'.$noor->id, (string) $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('h1', 'Confirm it\'s you');

        $crawler = $this->client->submit($crawler->selectButton('Confirm')->form(['password' => 'not it']));
        self::assertResponseStatusCodeSame(422);
        $this->client->submit($crawler->selectButton('Confirm')->form(['password' => self::PASSWORD]));
        self::assertResponseRedirects('/admin/users/'.$noor->id);

        // Then they manage the other admin: their address changes, and the old one is told
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Save')->form(['email' => 'noor@new.example']));
        self::assertResponseRedirects();
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame((string) $noor->email, $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Ada changed the email address of your account', (string) $email->getTextBody());
        self::assertNotSame($ada->id, $noor->id);
    }

    public function testAPasswordIsChosenByItsOwnerFromALink(): void
    {
        $grace = $this->createUser('Grace', ['editor']);
        $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/users/'.$grace->id);

        $this->client->submit($crawler->selectButton('Email a link to choose a new password')->form());
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('asked you to choose a new password', (string) $email->getTextBody());
        self::assertSame(1, preg_match('#/new-password/[0-9a-f]{32}\.[A-Za-z0-9_-]+#', (string) $email->getTextBody(), $link));
        self::assertCount(1, $this->audited('user.password_change_asked', $grace->id), 'In the activity log.');

        // Their password stays until they choose another, with the link
        $before = (string) $this->reload($grace)->passwordHash;
        $this->signOut();
        $this->client->request('GET', $link[0]);
        self::assertResponseRedirects('/new-password');
        $this->client->followRedirect();
        $this->client->submitForm('Change password', ['password' => 'a brand new one']);
        self::assertResponseRedirects('/login');
        self::assertNotSame($before, (string) $this->reload($grace)->passwordHash);
    }

    /**
     * A role of the site with admin access and the abilities, and a user with it.
     *
     * @param list<string> $abilities
     */
    private function delegate(string $role, array $abilities): User
    {
        $this->service(SiteRoles::class)->create($role, ucfirst($role));
        $this->service(Permissions::class)->grant($role, ['quire.admin.access', ...$abilities]);

        return $this->createUser(ucfirst($role), [$role], username: $role);
    }

    private function reload(User $user): User
    {
        return $this->service(UserRepository::class)->find($user->id) ?? throw new \LogicException('Gone.');
    }
}
