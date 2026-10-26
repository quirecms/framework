<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Testing\AdminTestCase;
use Quire\User\CreateUser;
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

    public function testUsersAreSearchedFilteredAndSorted(): void
    {
        $this->admin = $this->signIn('admin');
        $this->user('Grace Hopper', ['editor']);
        $suspended = $this->user('Alan Turing', ['member']);
        $this->service(UserWriter::class)->suspend($suspended->id);

        $crawler = $this->client->request('GET', '/admin/users');
        self::assertSame(['Admin', 'Alan Turing', 'Grace Hopper'], $this->names($crawler));
        self::assertSame('ascending', $crawler->filter('th[aria-sort]')->attr('aria-sort'));
        self::assertSelectorTextSame('main p.meta', '3 users');

        self::assertSame(['Grace Hopper'], $this->names($this->client->request('GET', '/admin/users?q=hopp')));
        self::assertSame(['Grace Hopper'], $this->names($this->client->request('GET', '/admin/users?role=editor')));
        self::assertSame(['Alan Turing'], $this->names($this->client->request('GET', '/admin/users?status=suspended')));
        self::assertSame(['Grace Hopper', 'Alan Turing', 'Admin'], $this->names($this->client->request('GET', '/admin/users?sort=-name')));
        self::assertSelectorTextContains('.status', 'Suspended');
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
        self::assertSelectorTextContains('.notice--success:nth-of-type(2), .notice--success', 'Added Grace Hopper.');
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
        self::assertSame('#email', $crawler->filter('.error-summary a')->attr('href'));

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
        self::assertSelectorTextSame('.notice--success', 'Grace Hopper is suspended: they can\'t sign in.');
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
