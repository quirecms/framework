<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Exception\InvalidContentException;
use Quire\User\CreateUser;
use Quire\User\Event\IdentityLinking;
use Quire\User\Event\UserDeleting;
use Quire\User\Exception\IdentityLinkRefusedException;
use Quire\User\Exception\InvalidUserException;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Authors, deleting users, linking identities, signing in through extensions, and URLs the site takes.
 */
final class IdentityTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    private UserWriter $writer;

    private ContentWriter $content;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = self::getContainer()->get(UserWriter::class);
        $this->content = self::getContainer()->get(ContentWriter::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testAuthorsAreUsers(): void
    {
        $ada = $this->user('ada');

        self::assertSame($ada->id, $this->content->create(new CreateContent('post', 'Mine', authorId: $ada->id, actorId: $ada->id))->authorId);

        foreach (['0190a3c4-0000-7000-8000-000000000000', 'not-a-uuid'] as $id) {
            try {
                $this->content->create(new CreateContent('post', 'Ghost written', authorId: $id));
                self::fail('An unknown author was accepted.');
            } catch (InvalidContentException $e) {
                self::assertSame(['author_id' => 'No user has this ID.'], $e->violations);
            }
        }

        $this->expectExceptionObject(InvalidContentException::single('actor_id', 'No user has this ID.'));
        $this->content->create(new CreateContent('post', 'By nobody', actorId: '0190a3c4-0000-7000-8000-000000000000'));
    }

    public function testDeletingAUserReassignsTheirContent(): void
    {
        $ada = $this->user('ada');
        $grace = $this->user('grace');
        $post = $this->content->create(new CreateContent('post', 'Notes', authorId: $ada->id, actorId: $ada->id));
        $deleting = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(UserDeleting::class, static function (UserDeleting $event) use (&$deleting): void {
            $deleting[] = [$event->user->username, $event->reassignTo?->username];
        });

        self::assertSame(1, $this->writer->contentCount($ada->id));
        $this->writer->delete($ada->id, reassignTo: $grace->id);

        self::assertNull(self::getContainer()->get(UserRepository::class)->find($ada->id));
        self::assertSame($grace->id, self::getContainer()->get(ContentRepository::class)->get($post->id)->authorId);
        self::assertNull($this->connection->fetchOne('SELECT author_id FROM quire.content_revision WHERE content_id = ?', [$post->id]), 'Revisions forget who made them.');
        self::assertSame([['ada', 'grace']], $deleting);

        $this->expectException(InvalidUserException::class);
        $this->writer->delete($grace->id, reassignTo: $grace->id);
    }

    public function testDeletingAUserCanKeepTheirContentWithoutAnAuthor(): void
    {
        $ada = $this->user('ada');
        $post = $this->content->create(new CreateContent('post', 'Notes', authorId: $ada->id));

        $this->writer->delete($ada->id);

        self::assertNull(self::getContainer()->get(ContentRepository::class)->get($post->id)->authorId);
    }

    public function testListenersCanRefuseLinkingAnIdentity(): void
    {
        $ada = $this->user('ada');
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(IdentityLinking::class, static function (IdentityLinking $event): void {
            $email = $event->claims['email'] ?? null;

            if (!\is_string($email) || !str_ends_with($email, '@example.com')) {
                $event->cancel('Only accounts of example.com can sign in.');
            }
        });

        $this->writer->linkIdentity($ada->id, 'google', '1', ['email' => 'ada@example.com']);
        self::assertSame($ada->id, self::getContainer()->get(UserRepository::class)->findByIdentity('google', '1')?->id);

        $this->expectExceptionObject(new IdentityLinkRefusedException('Only accounts of example.com can sign in.'));
        $this->writer->linkIdentity($ada->id, 'google', '2', ['email' => 'ada@example.org']);
    }

    public function testExtensionsAddWaysToSignIn(): void
    {
        $this->user('ada');

        $this->client->request('GET', '/_fixture/whoami', server: ['HTTP_X_FIXTURE_USER' => 'ada']);
        self::assertResponseIsSuccessful();
        self::assertSame('ada', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/_fixture/whoami', server: ['HTTP_X_FIXTURE_USER' => 'nobody']);
        self::assertResponseStatusCodeSame(401);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/_fixture/whoami');
        self::assertSame('anonymous', $this->client->getResponse()->getContent(), 'Other requests are left to the other ways.');
    }

    public function testContentCantTakeURLsOfTheSite(): void
    {
        foreach (['login', 'logout'] as $slug) {
            try {
                $this->content->create(new CreateContent('page', 'Clash', slug: $slug));
                self::fail(\sprintf('The page "/%s" was accepted.', $slug));
            } catch (InvalidContentException $e) {
                self::assertStringContainsString(\sprintf('The URL /%s is taken by a page of the site itself', $slug), $e->violations['slug'] ?? '');
            }
        }

        $page = $this->content->create(new CreateContent('page', 'Welcome'));

        $this->expectException(InvalidContentException::class);
        $this->content->update(new UpdateContent($page->id, $page->version)->slug('login'));
    }

    private function user(string $name): User
    {
        return $this->writer->create(new CreateUser(ucfirst($name), $name.'@example.com'));
    }
}
