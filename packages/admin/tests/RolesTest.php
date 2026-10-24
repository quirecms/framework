<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Permission\Permissions;
use Quire\Testing\ExtensionTestCase;
use Quire\User\CreateUser;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * Roles in the admin (D-20): a page per role, a review before saving, roles of the site's own, and
 * checking access.
 */
final class RolesTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin'];

    public function testTheOverviewShowsEachRole(): void
    {
        $this->signIn();
        $this->user('Grace', ['editor']);
        $this->service(Permissions::class)->grant('member', ['content.post.create']);

        $crawler = $this->client->request('GET', '/admin/roles');

        $rows = $crawler->filter('tbody tr')->each(static fn (Crawler $row): string => trim((string) preg_replace('/\s+/', ' ', $row->text())));
        self::assertSame([
            'Everyone guest everyone 2 of 22 Quire',
            'Member member · 1 change from the defaults No users 1 of 22 Quire',
            'Editor editor 1 user 15 of 22 Quire',
            'Admin admin 1 user everything but strict abilities Quire',
        ], $rows);
    }

    public function testARoleIsChangedAfterAReview(): void
    {
        $this->signIn();
        $this->user('Mia', ['member']);
        $crawler = $this->client->request('GET', '/admin/roles/member');

        self::assertSelectorTextContains('main p.meta', '1 user');
        self::assertCount(2, $crawler->filter('.ability-grid tbody tr'), 'Posts and pages.');
        self::assertSame('everyone may', $crawler->filter('#ability-content-post-view ~ .ability__everyone')->text());

        $crawler = $this->client->submit($this->tick($crawler->selectButton('Review the changes')->form(), ['content.post.create']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Change what “Member” may do?');
        self::assertSelectorTextContains('main', 'This applies to the 1 user with this role.');
        self::assertSame(['Create posts content.post.create'], $crawler->filter('.review-list__added')->each(static fn (Crawler $li): string => $li->text()));
        self::assertSelectorTextContains('.notice--warning', 'They may create posts, which is done in the admin, but they can\'t open it.');
        self::assertSame([], $this->service(Permissions::class)->abilitiesOf('member'), 'Nothing saved yet.');

        $this->client->submit($crawler->selectButton('Save')->form());
        self::assertResponseRedirects('/admin/roles/member', 303);
        self::assertSame(['content.post.create'], $this->service(Permissions::class)->abilitiesOf('member'));

        $crawler = $this->client->followRedirect();
        self::assertSame('changed: by default it hasn\'t this', $crawler->filter('#ability-content-post-create-state')->text());

        // Back to the defaults, reviewed the same way.
        $crawler = $this->client->submit($crawler->selectButton('Back to the defaults')->form());
        self::assertSame(['Create posts content.post.create'], $crawler->filter('.review-list__removed')->each(static fn (Crawler $li): string => $li->text()));
        $this->client->submit($crawler->selectButton('Save')->form());
        self::assertSame([], $this->service(Permissions::class)->abilitiesOf('member'));
    }

    public function testGrantsToEveryoneAndSiteManagementAreFlagged(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/admin/roles/guest');
        self::assertSelectorTextContains('main p.meta', 'Everyone, including visitors who aren\'t signed in.');

        $crawler = $this->client->submit($this->tick($crawler->selectButton('Review the changes')->form(), ['content.post.view', 'content.page.view', 'quire.settings.manage']));
        self::assertSelectorTextContains('main', 'This applies to everyone, including visitors who aren\'t signed in.');
        self::assertSame('for everyone', $crawler->filter('.review-list__added .tag--warning')->text());
    }

    public function testSavingWhatSomeoneChangedMeanwhileIsRefused(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/admin/roles/member');
        $crawler = $this->client->submit($this->tick($crawler->selectButton('Review the changes')->form(), ['content.post.create']));

        $this->service(Permissions::class)->grant('member', ['content.page.create']);
        $this->client->submit($crawler->selectButton('Save')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.notice--error', 'Someone changed this role while you reviewed it.');
        self::assertSame(['content.page.create'], $this->service(Permissions::class)->abilitiesOf('member'));
    }

    public function testAdminsOnlyGetStrictAbilities(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/admin/roles/admin');

        self::assertSelectorTextContains('.notice--warning', 'Admins may do everything, except strict abilities');
        self::assertCount(0, $crawler->filter('input[name="abilities[]"]'));
        self::assertSelectorTextContains('main', 'No ability is strict: admins may do everything.');
    }

    public function testTheSiteAddsRenamesAndDeletesRoles(): void
    {
        $this->signIn();
        $crawler = $this->client->request('GET', '/admin/roles');

        $this->client->submit($crawler->selectButton('Add the role')->form(['label' => 'Translator', 'name' => 'translator', 'from' => 'editor']));
        self::assertResponseRedirects('/admin/roles/translator', 303);
        $permissions = $this->service(Permissions::class);
        self::assertSame($permissions->abilitiesOf('editor'), $permissions->abilitiesOf('translator'));

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main p.meta', 'added on this site');
        $this->client->submit($crawler->selectButton('Rename')->form(['label' => 'Übersetzer']));
        self::assertSame('Übersetzer', $this->service(Permissions::class)->roles()['translator']);

        $user = $this->user('Ana', ['translator']);
        $crawler = $this->client->request('GET', '/admin/roles/translator/delete');
        self::assertSelectorTextContains('main', '1 user has it and will lose it');
        $this->client->submit($crawler->selectButton('Delete the role')->form());
        self::assertResponseRedirects('/admin/roles', 303);
        self::assertFalse($this->service(Permissions::class)->hasRole('translator'));
        self::assertSame([], $this->service(UserRepository::class)->find($user->id)?->roles);

        $crawler = $this->client->request('GET', '/admin/roles');
        $this->client->submit($crawler->selectButton('Add the role')->form(['label' => 'Editors', 'name' => 'editor']));
        $this->client->followRedirect();
        self::assertSelectorTextSame('.notice--error', 'There\'s a role "editor" already.');
        $this->client->request('GET', '/admin/roles/editor/delete');
        self::assertSelectorTextContains('.notice--error', 'Only roles added on this site can be deleted');
    }

    public function testAccessIsExplained(): void
    {
        $this->signIn();
        $this->user('Grace', ['editor']);

        $crawler = $this->client->request('GET', '/admin/access?user=grace&ability=quire.settings.manage');
        self::assertSame('No Grace may not: Change the site\'s settings', $crawler->filter('.access-result h2')->text());
        self::assertSame(['None of their roles (editor) has it.'], $crawler->filter('.access-result li')->each(static fn (Crawler $li): string => $li->text()));
        self::assertSame(['Admin 1 user'], $crawler->filter('.card')->last()->filter('.item-list li')->each(static fn (Crawler $li): string => $li->text()));

        $crawler = $this->client->request('GET', '/admin/access?user=grace&ability=content.post.publish');
        self::assertSame('Yes Grace may: Publish posts', $crawler->filter('.access-result h2')->text());

        $this->client->request('GET', '/admin/access?user=nobody&ability=content.post.publish');
        self::assertSelectorTextSame('.notice--error', 'No user with the username or email “nobody”.');
        self::assertSelectorNotExists('.access-result');
    }

    /**
     * @param list<string> $abilities Ticked, besides those that already are
     */
    private function tick(Form $form, array $abilities): Form
    {
        $fields = $form['abilities'];
        \assert(\is_array($fields));

        foreach ($fields as $field) {
            \assert($field instanceof ChoiceFormField);

            if (\in_array($field->availableOptionValues()[0], $abilities, true)) {
                $field->tick();
            }
        }

        return $form;
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $name, array $roles): \Quire\User\User
    {
        return $this->service(UserWriter::class)->create(new CreateUser($name, strtolower($name).'@example.com', roles: $roles));
    }

    private function signIn(): void
    {
        $this->service(UserWriter::class)->create(new CreateUser('Admin', 'admin@example.com', password: 'correct-horse-42', roles: ['admin']));
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'admin', '_password' => 'correct-horse-42']);
    }
}
