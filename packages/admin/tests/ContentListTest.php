<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Admin\Content\ContentLists;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Testing\ExtensionTestCase;
use Quire\User\CreateUser;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Component\DomCrawler\Crawler;

final class ContentListTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin'];

    public function testEachTypeTheUserMayEditHasAListInTheMenu(): void
    {
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin');

        self::assertSame(['Posts', 'Pages'], $crawler->filter('.admin-nav__section')->eq(1)->filter('a')->each(static fn (Crawler $a): string => $a->text()));
        self::assertSame('/admin/content/post', $crawler->filter('.admin-nav a')->eq(1)->attr('href'));

        $this->client->request('GET', '/admin/content/post');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('title', 'Posts · Admin · Quire');
        self::assertSelectorTextSame('.admin-nav a[aria-current=page]', 'Posts');

        $this->client->request('GET', '/admin/content/nope');
        self::assertResponseStatusCodeSame(404);
    }

    public function testViewsByStatusAndTheTrash(): void
    {
        $this->post('Draft one');
        $this->post('Published one', publish: true);
        $this->post('Archived one', publish: true, then: Status::ARCHIVE);
        $trashed = $this->post('Trashed one');
        $this->service(ContentWriter::class)->trash($trashed->id, $trashed->version);
        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertSame(['All 3', 'Draft 1', 'Published 1', 'Archived 1', 'Trash 1'], $crawler->filter('.views a')->each(static fn (Crawler $a): string => $a->text()), 'Views without items are left out.');
        self::assertSame(['Archived one', 'Published one', 'Draft one'], $this->titles($crawler), 'The latest change first.');

        self::assertSame(['Draft one'], $this->titles($this->client->request('GET', '/admin/content/post?view=draft')));
        self::assertSame(['Trashed one'], $this->titles($crawler = $this->client->request('GET', '/admin/content/post?view=trash')));
        self::assertSame(['Restore', 'Delete permanently'], $this->rowActions($crawler, 0));
        self::assertSame(['Archived one', 'Published one', 'Draft one'], $this->titles($this->client->request('GET', '/admin/content/post?view=nope')), 'An unknown view is "All".');
    }

    public function testSearchFindsWordsAsTheyAreTyped(): void
    {
        $this->post('Markdown editor');
        $this->post('Gardening', body: 'Notes on marigolds.');
        $this->post('Cooking');
        $this->signIn('editor');

        self::assertSame(['Markdown editor', 'Gardening'], $this->titles($crawler = $this->client->request('GET', '/admin/content/post?q=mar')), 'Best matches first: the title weighs most.');
        self::assertSame('mar', $crawler->filter('input[name=q]')->attr('value'));
        self::assertSame([], $this->titles($crawler = $this->client->request('GET', '/admin/content/post?q=zzz')));
        self::assertSelectorTextSame('.empty', 'Nothing matches.');
    }

    public function testSortingByAColumn(): void
    {
        $this->post('Banana');
        $this->post('Apple');
        $this->post('Cherry');
        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin/content/post');
        $title = $crawler->filter('thead th')->reduce(static fn (Crawler $th): bool => 'Title' === $th->text());
        self::assertNull($title->attr('aria-sort'));
        self::assertSame('descending', $crawler->filter('thead th[aria-sort]')->attr('aria-sort'), 'By "Updated", newest first.');

        $crawler = $this->client->click($title->filter('a')->link());
        self::assertSame(['Apple', 'Banana', 'Cherry'], $this->titles($crawler));
        self::assertSame('ascending', $crawler->filter('thead th[aria-sort]')->attr('aria-sort'));

        $crawler = $this->client->click($crawler->filter('thead th[aria-sort] a')->link());
        self::assertSame(['Cherry', 'Banana', 'Apple'], $this->titles($crawler), 'Choosing it again reverses it.');
    }

    public function testPages(): void
    {
        foreach (range(1, ContentLists::PER_PAGE + 2) as $number) {
            $this->post(\sprintf('Post %02d', $number));
        }

        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin/content/post?sort=title');
        self::assertCount(ContentLists::PER_PAGE, $this->titles($crawler));
        self::assertSelectorTextSame('.pagination span', 'Page 1 of 2');

        $crawler = $this->client->click($crawler->filter('.pagination a[rel=next]')->link());
        self::assertSame(['Post 21', 'Post 22'], $this->titles($crawler), 'The sort is kept.');

        $this->client->request('GET', '/admin/content/post?page=9');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRowActionsChangeTheStatusAndKeepTheList(): void
    {
        $draft = $this->post('Draft');
        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin/content/post?view=draft&sort=title');
        self::assertSame(['Publish', 'Move to trash'], $this->rowActions($crawler, 0));

        $this->client->submit($crawler->filter(\sprintf('button[value="transition:publish|%s"]', $draft->id))->form());

        self::assertResponseRedirects('/admin/content/post?view=draft&sort=title', 303);
        self::assertSame(Status::PUBLISHED, $this->service(ContentRepository::class)->get($draft->id)->status);

        $this->client->followRedirect();
        self::assertSelectorTextSame('.notice--success', 'Publish: done for 1 item.');
    }

    public function testBulkActionsOnTheSelectedItems(): void
    {
        $one = $this->post('One');
        $two = $this->post('Two', publish: true);
        $three = $this->post('Three');
        $this->signIn('editor');

        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertSame(['', 'transition:publish', 'transition:unpublish', 'transition:archive', 'trash'], $crawler->filter('select[name=action] option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));

        $token = (string) $crawler->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/content/post', ['_token' => $token, 'action' => 'transition:publish', 'ids' => [$one->id, $two->id]]);
        $this->client->followRedirect();

        self::assertSelectorTextSame('.notice--success', 'Publish: done for 1 item.');
        self::assertSelectorTextSame('.notice--error', 'Publish wasn\'t possible for “Two”: This isn\'t possible for it now.', 'It\'s published already.');

        $this->client->request('POST', '/admin/content/post', ['_token' => $token, 'action' => 'trash', 'ids' => [$one->id, $three->id]]);
        $repository = $this->service(ContentRepository::class);
        self::assertNotNull($repository->get($one->id)->deletedAt);
        self::assertNotNull($repository->get($three->id)->deletedAt);

        $this->client->request('POST', '/admin/content/post', ['_token' => $token, 'action' => 'restore', 'ids' => [$one->id]]);
        self::assertNull($repository->get($one->id)->deletedAt);

        $this->client->request('POST', '/admin/content/post', ['_token' => $token, 'action' => 'purge', 'ids' => [$three->id]]);
        self::assertNull($repository->find($three->id));

        $this->client->request('POST', '/admin/content/post', ['_token' => $token, 'action' => 'purge', 'ids' => [$two->id]]);
        self::assertNotNull($repository->find($two->id), 'Only items in the trash are deleted permanently.');
    }

    public function testActionsNeedAValidFormToken(): void
    {
        $draft = $this->post('Draft');
        $this->signIn('editor');

        $this->client->request('POST', '/admin/content/post', ['_token' => 'forged', 'row' => 'transition:publish|'.$draft->id]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Status::DRAFT, $this->service(ContentRepository::class)->get($draft->id)->status);
    }

    public function testWhoMayDoWhat(): void
    {
        $member = $this->signIn('member');
        $theirs = $this->post('Theirs', author: $member);
        $others = $this->post('Others');

        $this->client->request('GET', '/admin/content/post');
        self::assertResponseStatusCodeSame(403, 'Members don\'t use the admin.');

        // Members who may write their own posts, but not publish or delete them.
        $this->service(Permissions::class)->grant('member', ['quire.admin.access', 'content.post.edit_own']);
        $crawler = $list = $this->client->request('GET', '/admin/content/post');
        self::assertResponseIsSuccessful();
        self::assertSame(['Theirs'], $this->titles($crawler), 'Only their own.');
        self::assertSame([], $this->rowActions($crawler, 0));
        self::assertCount(0, $crawler->filter('select[name=action]'), 'Nothing to do with selected items.');
        $this->client->request('GET', '/admin/content/page');
        self::assertResponseStatusCodeSame(403, 'Not pages.');

        $token = (string) $list->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/content/post', ['_token' => $token, 'action' => 'trash', 'ids' => [$theirs->id, $others->id]]);
        $repository = $this->service(ContentRepository::class);
        self::assertNull($repository->get($theirs->id)->deletedAt);
        self::assertNull($repository->get($others->id)->deletedAt, 'Items they can\'t see in the list are out of reach.');
    }

    private function post(string $title, string $body = '', bool $publish = false, ?string $then = null, ?User $author = null): Content
    {
        $writer = $this->service(ContentWriter::class);
        $item = $writer->create(new CreateContent('post', $title, body: $body, authorId: $author?->id));

        if ($publish) {
            $item = $writer->transition($item->id, $item->version, Status::PUBLISH);
        }

        if (null !== $then) {
            return $writer->transition($item->id, $item->version, $then);
        }

        return $item;
    }

    /**
     * @return list<string>
     */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('.content-list__title')->each(static fn (Crawler $title): string => $title->text());
    }

    /**
     * @return list<string>
     */
    private function rowActions(Crawler $crawler, int $row): array
    {
        return $crawler->filter('tbody tr')->eq($row)->filter('.content-list__actions button')->each(static fn (Crawler $button): string => $button->filter('.visually-hidden')->count() > 0 ? trim(str_replace($button->filter('.visually-hidden')->text(), '', $button->text())) : $button->text());
    }

    private function signIn(string $role): User
    {
        $user = $this->service(UserWriter::class)->create(new CreateUser(ucfirst($role), $role.'@example.com', password: 'correct-horse-42', roles: [$role]));
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => $role, '_password' => 'correct-horse-42']);

        return $user;
    }
}
