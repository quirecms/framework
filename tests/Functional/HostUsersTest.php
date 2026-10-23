<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\HostAppKernel;
use App\Tests\Support\HostUserProvider;
use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Exception\InvalidContentException;
use Quire\Permission\Permissions;
use Quire\User\CurrentUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Quire inside an application that signs in its own users (D-13, D-14).
 */
final class HostUsersTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    protected static function getKernelClass(): string
    {
        return HostAppKernel::class;
    }

    protected function setUp(): void
    {
        HostUserProvider::$roles = ['olga' => ['ROLE_ORGANIZER'], 'boss' => ['ROLE_OWNER'], 'pat' => ['ROLE_PLAYER']];
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Draw for the final'));
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheApplicationsUsersGetLinkedQuireUsersWithMappedRoles(): void
    {
        $this->client->loginUser(new InMemoryUser('olga', null, ['ROLE_ORGANIZER']));
        $this->client->request('GET', '/blog/draw-for-the-final');
        self::assertResponseIsSuccessful('Organizers are editors, who may preview drafts.');

        $user = $this->linked('olga');
        self::assertSame('olga', $user->displayName);
        self::assertSame([], $user->roles, 'Mapped roles aren\'t stored as Quire\'s…');
        self::assertSame(['editor'], $this->claims('olga')['roles'] ?? null, '…but kept for display.');
        self::assertSame(1, $this->userCount(), 'Linked once.');

        $this->client->request('GET', '/blog/draw-for-the-final');
        self::assertSame(1, $this->userCount());
    }

    public function testRoleChangesInTheApplicationApplyAtOnce(): void
    {
        $this->client->loginUser(new InMemoryUser('olga', null, ['ROLE_ORGANIZER']));
        $this->client->request('GET', '/blog/draw-for-the-final');
        self::assertResponseIsSuccessful();

        // No longer an organizer: the next request knows. (Symfony signs users out whose roles changed,
        // so they sign in again.)
        HostUserProvider::$roles['olga'] = ['ROLE_PLAYER'];
        $this->client->loginUser(new InMemoryUser('olga', null, ['ROLE_PLAYER']));
        $this->client->request('GET', '/blog/draw-for-the-final');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->claims('olga')['roles'] ?? null);
    }

    public function testRoleHierarchyAndQuireRolesAdd(): void
    {
        $current = self::getContainer()->get(CurrentUser::class);
        self::assertSame(['admin', 'editor'], $current->mappedRoles(new InMemoryUser('boss', null, ['ROLE_OWNER'])), 'Owners are organizers too.');

        $this->client->loginUser(new InMemoryUser('pat', null, ['ROLE_PLAYER']));
        $this->client->request('GET', '/blog/draw-for-the-final');
        self::assertResponseStatusCodeSame(404);

        self::getContainer()->get(UserWriter::class)->setRoles($this->linked('pat')->id, ['editor']);
        $current->reset();
        $this->client->request('GET', '/blog/draw-for-the-final');
        self::assertResponseIsSuccessful('Roles given in Quire add to the mapped ones.');

        self::getContainer()->get(UserWriter::class)->suspend($this->linked('pat')->id);
        $current->reset();
        $this->client->request('GET', '/blog/draw-for-the-final');
        self::assertResponseStatusCodeSame(404, 'Suspended in Quire: no Quire roles.');
    }

    public function testTheApplicationSignsUsersIn(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseStatusCodeSame(404, 'Quire has no sign-in page of its own here.');

        $permissions = self::getContainer()->get(Permissions::class);
        $permissions->revoke('guest', ['content.post.view']);
        $permissions->grant('member', ['content.post.view']);
        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(401, 'The application\'s firewall asks visitors to sign in.');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Basic realm="Secured Area"');
    }

    public function testContentCantTakeTheApplicationsURLs(): void
    {
        $this->expectExceptionObject(InvalidContentException::single('slug', 'The URL /tournaments is taken by a page of the site itself (the route "app_tournaments"). Choose another slug.'));
        self::getContainer()->get(ContentWriter::class)->create(new CreateContent('page', 'Tournaments'));
    }

    private function userCount(): int
    {
        $count = $this->connection->fetchOne('SELECT count(*) FROM quire.app_user');

        return \is_int($count) ? $count : -1;
    }

    private function linked(string $identifier): User
    {
        $user = self::getContainer()->get(UserRepository::class)->findByIdentity(CurrentUser::HOST_PROVIDER, $identifier);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(string $identifier): array
    {
        return self::getContainer()->get(UserRepository::class)->identityClaims(CurrentUser::HOST_PROVIDER, $identifier) ?? [];
    }
}
