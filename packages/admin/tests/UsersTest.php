<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Testing\AdminTestCase;
use Quire\User\CreateUser;
use Quire\User\Invitations;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Mime\Email;

/**
 * Users in the admin: finding, adding (invited by email), changing, suspending and deleting them.
 */
final class UsersTest extends AdminTestCase
{
    private User $admin;

    public function testOnlyThoseAllowedManageUsers(): void
    {
        $this->signIn('editor');

        foreach (['/admin/users', '/admin/users/new', '/admin/roles', '/admin/access'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
    }

    public function testPeopleByRoleAndStatusWithTheNextStep(): void
    {
        $this->admin = $this->signIn('admin');
        $this->user('Grace Hopper', ['editor']);
        $suspended = $this->user('Alan Turing', ['member']);
        $this->service(UserWriter::class)->suspend($suspended->id);
        $lin = $this->user('Lin Tao', ['editor']);
        $this->service(Invitations::class)->invite($lin);

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertSame(['Admin', 'Alan Turing', 'Grace Hopper', 'Lin Tao'], $this->names($crawler));
        self::assertSelectorTextSame('h1', 'Everyone 4');
        self::assertSame(['Everyone 4', 'Member 1', 'Editor 2', 'Admin 1', 'Invited, not joined 1', 'Suspended 1'], $crawler->filter('.q-views a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('ascending', $crawler->filter('th[aria-sort]')->attr('aria-sort'));

        // Each one's status, and the one thing to do about it.
        $rows = $crawler->filter('tbody tr')->each(static fn (Crawler $tr): array => [$tr->filter('a.people-person__name')->text(), trim((string) preg_replace('/\s+/', ' ', $tr->filter('td')->eq(1)->text())), trim($tr->filter('td.people-next')->text())]);
        self::assertSame(['Admin', 'Active new', 'Activity (Admin)'], $rows[0]);
        self::assertSame(['Alan Turing', 'Suspended', 'Let in again (Alan Turing)'], $rows[1]);
        self::assertSame(['Lin Tao', 'Invited just now', 'Send again (Lin Tao)'], $rows[3]);
        self::assertSame('not yet', $crawler->filter('tbody tr')->eq(3)->filter('td')->eq(2)->text());

        self::assertSame(['Grace Hopper'], $this->names($this->client->request('GET', '/admin/users?q=hopp')));
        self::assertSame(['Grace Hopper', 'Lin Tao'], $this->names($this->client->request('GET', '/admin/users?view=role:editor')));
        self::assertSame(['Lin Tao'], $this->names($this->client->request('GET', '/admin/users?view=invited')));
        self::assertSelectorTextSame('h1', 'Invited, not joined 1');
        self::assertSame(['Alan Turing'], $this->names($this->client->request('GET', '/admin/users?view=suspended')));
        self::assertSame(['Lin Tao', 'Grace Hopper', 'Alan Turing', 'Admin'], $this->names($this->client->request('GET', '/admin/users?sort=-name')));

        // Letting someone in again, from the list, back to it.
        $crawler = $this->client->request('GET', '/admin/users?view=suspended');
        $this->client->submit($crawler->selectButton('Let in again (Alan Turing)')->form());
        self::assertResponseRedirects('/admin/users?view=suspended', 303);
        self::assertTrue($this->service(UserRepository::class)->find($suspended->id)?->isActive());
    }

    public function testInvitingManyPeopleAtOnce(): void
    {
        $this->admin = $this->signIn('admin');
        $this->user('Grace Hopper', ['editor']);
        $crawler = $this->client->request('GET', '/admin/users');

        self::assertSame('As Editor, they can write, edit, review, publish, and delete Posts and Pages. Also: Use the admin interface.', $crawler->filter('#invite-role option[value=editor]')->attr('data-sentence'));
        self::assertSame('As Admin, they can do everything.', $crawler->filter('#invite-role option[value=admin]')->attr('data-sentence'));
        self::assertSelectorTextSame('#invite-sentence', (string) $crawler->filter('#invite-role option[selected]')->attr('data-sentence'), 'Said for the role chosen.');

        $this->client->submit($crawler->filter('.invite-form')->form(['emails' => "ana@example.org, ben@example.org\nnot-an-email grace@example.com", 'role' => 'editor']));
        self::assertResponseRedirects('/admin/users?view=invited', 303);
        self::assertEmailCount(3, message: 'Grace is a user already: they\'re invited again.');
        $ana = $this->service(UserRepository::class)->findByEmail('ana@example.org');
        self::assertInstanceOf(User::class, $ana);
        self::assertSame(['editor'], $ana->roles);
        self::assertSame('ana', $ana->displayName);
        $this->assertAudited('user.invited', $ana->id);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.q-notice--success', 'Invited 3 people by email');
        self::assertSelectorTextContains('.q-notice--error', 'not-an-email: That isn\'t an email.');
    }

    public function testNewUsersAreInvitedToChooseTheirPassword(): void
    {
        $this->admin = $this->signIn('admin');
        $crawler = $this->client->request('GET', '/admin/users/new');
        self::assertSame('invite', $crawler->filter('input[name=password_mode]:checked')->attr('value'), 'Inviting is the default.');

        $this->client->submit($this->roles($crawler->selectButton('Add the user')->form(['display_name' => 'Grace Hopper', 'email' => 'grace@example.com']), ['editor']));
        self::assertEmailCount(1);
        $grace = $this->service(UserRepository::class)->findByEmail('grace@example.com');
        self::assertInstanceOf(User::class, $grace);
        self::assertResponseRedirects('/admin/users/'.$grace->id, 303);
        self::assertSame(['editor'], $grace->roles);
        self::assertNull($grace->passwordHash);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('Admin added you to', (string) $email->getTextBody());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.q-notice--success:nth-of-type(2), .q-notice--success', 'Added Grace Hopper.');
    }

    public function testMistakesAreShownWithTheForm(): void
    {
        $this->admin = $this->signIn('admin');
        $this->user('Grace Hopper', []);
        $crawler = $this->client->request('GET', '/admin/users/new');

        $crawler = $this->client->submit($crawler->selectButton('Add the user')->form(['display_name' => 'Grace Two', 'email' => 'grace.hopper@example.com', 'password_mode' => 'set', 'password' => 'short']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Passwords must be at least 8 characters long.', $crawler->filter('#password-error')->text());
        self::assertSame('Grace Two', $crawler->filter('#display_name')->attr('value'), 'What was entered stays.');

        $crawler = $this->client->submit($crawler->selectButton('Add the user')->form(['password_mode' => 'set', 'password' => 'long enough now']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Another user has this email.', $crawler->filter('#email-error')->text());
        self::assertSame('#email', $crawler->filter('.q-error-summary a')->attr('href'));

        $crawler = $this->client->submit($crawler->selectButton('Add the user')->form(['email' => '', 'password_mode' => 'invite']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('An invitation goes to the user\'s email, so enter one.', $crawler->filter('#email-error')->text());
    }

    public function testUsersAreChanged(): void
    {
        $this->admin = $this->signIn('admin');
        $grace = $this->user('Grace Hopper', ['member']);
        $crawler = $this->client->request('GET', '/admin/users/'.$grace->id);

        $this->client->submit($this->roles($crawler->selectButton('Save')->form(['display_name' => 'Grace B. Hopper', 'username' => 'amazing-grace', 'password' => 'a new horse battery']), ['editor']));
        self::assertResponseRedirects('/admin/users/'.$grace->id, 303);

        $grace = $this->reload($grace);
        self::assertSame('Grace B. Hopper', $grace->displayName);
        self::assertSame('amazing-grace', $grace->username);
        self::assertSame(['editor'], $grace->roles);
        self::assertNotNull($grace->passwordHash);
    }

    public function testUsersAreSuspendedButNotOneself(): void
    {
        $this->admin = $this->signIn('admin');
        $grace = $this->user('Grace Hopper', ['editor']);
        $crawler = $this->client->request('GET', '/admin/users/'.$grace->id);

        $this->client->submit($crawler->selectButton('Suspend')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Grace Hopper is suspended: they can\'t sign in.');
        self::assertFalse($this->reload($grace)->isActive());
        $this->client->submit($crawler->selectButton('Activate')->form());
        self::assertTrue($this->reload($grace)->isActive());

        $crawler = $this->client->request('GET', '/admin/users/'.$this->admin->id);
        self::assertCount(0, $crawler->filter('button:contains("Suspend"), a:contains("Delete")'), 'Not for oneself.');
        $this->client->request('GET', '/admin/users/'.$this->admin->id.'/delete');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheLastAdminKeepsTheRole(): void
    {
        $this->admin = $this->signIn('admin');

        // The only admin gives up the role.
        $crawler = $this->client->request('GET', '/admin/users/'.$this->admin->id);
        $crawler = $this->client->submit($this->roles($crawler->selectButton('Save')->form(), ['editor']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You can\'t take the admin role from the last active admin', $crawler->filter('#roles-error')->text());
        self::assertContains('admin', $this->reload($this->admin)->roles);
    }

    public function testDeletingHandsTheContentOn(): void
    {
        $this->admin = $this->signIn('admin');
        $grace = $this->user('Grace Hopper', ['editor']);
        $ada = $this->user('Ada Lovelace', ['editor']);
        $post = $this->service(ContentWriter::class)->create(new CreateContent('post', 'Compilers', authorId: $grace->id));

        $crawler = $this->client->request('GET', '/admin/users/'.$grace->id.'/delete');
        self::assertSelectorTextSame('label[for=reassign]', 'Who gets their 1 item?');
        $this->client->submit($crawler->selectButton('Delete Grace Hopper')->form(['reassign' => $ada->id]));

        self::assertResponseRedirects('/admin/users', 303);
        self::assertNull($this->service(UserRepository::class)->find($grace->id));
        self::assertSame($ada->id, $this->service(ContentRepository::class)->get($post->id)->authorId);
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $name, array $roles): User
    {
        return $this->service(UserWriter::class)->create(new CreateUser($name, strtolower(str_replace(' ', '.', $name)).'@example.com', roles: $roles));
    }

    /**
     * @param list<string> $roles Exactly these ticked
     */
    private function roles(Form $form, array $roles): Form
    {
        $fields = $form['roles'];
        \assert(\is_array($fields));

        foreach ($fields as $field) {
            \assert($field instanceof ChoiceFormField);
            \in_array($field->availableOptionValues()[0], $roles, true) ? $field->tick() : $field->untick();
        }

        return $form;
    }

    private function reload(User $user): User
    {
        return $this->service(UserRepository::class)->find($user->id) ?? throw new \LogicException('Gone.');
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('tbody th a')->each(static fn (Crawler $a): string => $a->text());
    }
}
