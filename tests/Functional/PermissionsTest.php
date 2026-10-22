<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\User\CreateUser;
use Quire\User\Exception\InvalidUserException;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * Roles, abilities and rules, and what they mean for content: drafts and members-only types.
 */
final class PermissionsTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    private Permissions $permissions;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->permissions = self::getContainer()->get(Permissions::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testEveryContentTypeHasItsAbilities(): void
    {
        self::assertSame(['guest', 'member', 'editor', 'admin', 'moderator'], array_keys($this->permissions->roles()));

        foreach (['view', 'view_drafts', 'create', 'edit_own', 'edit_any', 'publish', 'delete'] as $action) {
            self::assertTrue($this->permissions->has('content.greeting_card.'.$action), $action);
        }

        self::assertSame(['guest'], $this->permissions->rolesWith('content.post.view'));
        self::assertSame(['editor'], $this->permissions->rolesWith('content.post.publish'));
        self::assertSame(['moderator', 'editor'], $this->permissions->rolesWith('quire-fixture-hello.moderate'));
    }

    public function testRolesGrantAbilities(): void
    {
        $member = $this->user('member', ['member']);
        $editor = $this->user('editor', ['editor']);
        $admin = $this->user('admin', ['admin']);

        self::assertTrue($this->can(null, 'content.post.view'), 'Guests view published posts.');
        self::assertFalse($this->can(null, 'content.post.view_drafts'));
        self::assertTrue($this->can($member, 'content.post.view'), 'Everyone has the guests\' abilities.');
        self::assertFalse($this->can($member, 'content.post.publish'));
        self::assertTrue($this->can($editor, 'content.post.publish'));
        self::assertTrue($this->can($admin, 'quire-fixture-hello.moderate'), 'Admins have every ability…');
        self::assertFalse($this->can($admin, 'quire-fixture-hello.purge'), '…but strict ones.');

        $this->permissions->grant('admin', ['quire-fixture-hello.purge']);
        self::assertTrue($this->can($admin, 'quire-fixture-hello.purge'), 'Strict abilities can be granted to admins.');
    }

    public function testAdminsChangeGrantsOnTopOfTheDefaults(): void
    {
        $member = $this->user('member', ['member']);

        $this->permissions->grant('member', ['content.post.view_drafts']);
        $this->permissions->revoke('editor', ['content.post.delete']);
        self::assertTrue($this->can($member, 'content.post.view_drafts'));
        self::assertSame(['editor', 'member'], $this->permissions->rolesWith('content.post.view_drafts'));
        self::assertSame([], $this->permissions->rolesWith('content.post.delete'));
        self::assertSame(['grant' => ['content.post.view_drafts' => ['member']], 'revoke' => ['content.post.delete' => ['editor']]], $this->permissions->changes(), 'Only differences from the defaults are kept.');

        $this->permissions->grant('editor', ['content.post.delete']);
        self::assertSame(['grant' => ['content.post.view_drafts' => ['member']], 'revoke' => []], $this->permissions->changes(), 'Granting a default back removes the revocation.');

        $this->permissions->restoreDefaults();
        self::assertSame(['grant' => [], 'revoke' => []], $this->permissions->changes());
        self::assertFalse($this->can($member, 'content.post.view_drafts'));

        $this->expectExceptionMessageIsOrContains('Unknown ability "content.post.fly".');
        $this->permissions->grant('member', ['content.post.fly']);
    }

    public function testRulesOfExtensionsGrantAndRefuse(): void
    {
        $helper = $this->user('helper', []);
        $admin = $this->user('admin', ['admin']);
        $locked = $this->publish('Locked');

        self::assertTrue($this->can($helper, 'quire-fixture-hello.moderate'), 'A rule grants it without a role.');
        self::assertFalse($this->can($admin, 'content.post.edit_any', $locked), 'A rule\'s refusal wins, even for admins.');
        self::assertTrue($this->can($admin, 'content.post.edit_any', $this->publish('Open')));
    }

    public function testEditingOwnItemsIsForTheirAuthors(): void
    {
        $author = $this->user('author', ['editor']);
        $other = $this->user('other', ['editor']);
        $item = $this->publish('Mine', authorId: $author->id);

        self::assertTrue($this->can($author, 'content.post.edit_own', $item));
        self::assertFalse($this->can($other, 'content.post.edit_own', $item));
        self::assertTrue($this->can($other, 'content.post.edit_any', $item));
    }

    public function testRolesOfExtensionsCanBeGivenToUsers(): void
    {
        self::assertSame(['moderator'], $this->user('mod', ['moderator'])->roles);

        $this->expectException(InvalidUserException::class);
        $this->user('wizard', ['wizard']);
    }

    public function testDraftsAreVisibleOnlyToThoseWhoMayPreviewThem(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $writer->create(new CreateContent('post', 'Secret plans', body: 'Not yet.'));
        $this->publish('Published post');

        $this->client->request('GET', '/blog/secret-plans');
        self::assertResponseStatusCodeSame(404, 'Visitors don\'t see drafts.');

        $this->client->loginUser($this->user('member', ['member']));
        $this->client->request('GET', '/blog/secret-plans');
        self::assertResponseStatusCodeSame(404, 'Nor do members.');

        $this->client->loginUser($this->user('editor', ['editor']));
        $this->client->request('GET', '/blog/secret-plans');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('article .notice', 'Preview');
        self::assertSelectorTextSame('article h1', 'Secret plans');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('GET', '/');
        self::assertSelectorTextNotContains('main', 'Secret plans', 'Lists show published items only, even to editors.');
        self::assertSelectorTextContains('main', 'Published post');
    }

    public function testTypesCanBeForMembersOnly(): void
    {
        $this->permissions->revoke('guest', ['content.post.view']);
        $this->permissions->grant('member', ['content.post.view']);
        $this->publish('Members news');

        $this->client->request('GET', '/blog/members-news');
        self::assertResponseRedirects('/login?next=/blog/members-news', message: 'Visitors are asked to sign in.');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login?next=/', message: 'So is a list of them.');
        self::assertSame([], self::getContainer()->get(ContentRepository::class)->query('post')->get(), 'Queries leave out what visitors may not see.');

        $this->client->loginUser($this->user('member', ['member']));
        $this->client->request('GET', '/blog/members-news');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('main', 'Members news');

        $this->client->loginUser($this->user('moderator', ['moderator']));
        $this->client->request('GET', '/blog/members-news');
        self::assertResponseStatusCodeSame(403, 'Signed-in users without the ability are refused.');
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $name, array $roles): User
    {
        return self::getContainer()->get(UserWriter::class)->create(new CreateUser(ucfirst($name), $name.'@example.com', roles: $roles));
    }

    private function publish(string $title, ?string $authorId = null): Content
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $item = $writer->create(new CreateContent('post', $title, authorId: $authorId));

        return $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));
    }

    private function can(?User $user, string $ability, mixed $subject = null): bool
    {
        $token = $user instanceof User ? new UsernamePasswordToken($user, 'main', $user->getRoles()) : new NullToken();

        return self::getContainer()->get(AccessDecisionManagerInterface::class)->decide($token, [$ability], $subject);
    }
}
