<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Quire\Action\Actions;
use Quire\Action\Exception\ActionDeniedException;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Action\Exception\UnknownActionException;
use Quire\Audit\AuditLog;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Settings\Settings;
use Quire\User\CreateUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Core's actions (see packages/core/extend.php): each has a console command that accepts the command
 * lines it writes, so what the admin and the audit log show as "the same as a command" can be run.
 */
final class ActionsTest extends KernelTestCase
{
    private const string ITEM = '0192b6a0-0000-7000-8000-000000000001';

    private const string CHANGES = '0192b6a1-0000-7000-8000-000000000002';

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

    /**
     * Arguments for each of core's actions: a new action needs its own here.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function samples(): array
    {
        $item = ['content' => self::ITEM];
        $changes = [...$item, 'pending_revision' => self::CHANGES];
        $ada = ['user' => 'ada'];
        $translator = ['role' => 'translator'];
        $extensions = ['extensions' => ['acme-reviews', 'acme-shop']];
        $samples = [
            'content.create' => ['type' => 'post', 'title' => 'Hello, "world"', 'slug' => 'hello', 'locale' => 'de', 'parent' => self::ITEM, 'author' => 'ada'],
            'content.update' => [...$item, 'title' => 'It\'s new', 'slug' => 'new', 'excerpt' => 'Short.'],
            'content.transition' => [...$item, 'transition' => Status::SCHEDULE, 'at' => '2026-12-24T09:00:00+01:00'],
            'content.trash' => $item,
            'content.restore' => $item,
            'content.purge' => $item,
            'content.submit_changes' => $changes,
            'content.review_changes' => [...$changes, 'verdict' => 'request_changes', 'message' => 'Add a source, please.'],
            'content.schedule_changes' => [...$changes, 'at' => '2026-12-24T09:00:00+01:00'],
            'content.publish_changes' => $changes,
            'content.discard_changes' => $changes,
            'user.create' => ['email' => 'ada@example.com', 'name' => 'Ada Lovelace', 'username' => 'ada', 'roles' => ['editor', 'translator'], 'password' => 'correct-horse-42'],
            'user.invite' => ['users' => ['ada', 'kim@example.com'], 'roles' => ['editor']],
            'user.update' => [...$ada, 'name' => 'Ada L.', 'email' => 'ada@example.org', 'username' => 'ada-l'],
            'user.change_roles' => [...$ada, 'add' => ['editor'], 'remove' => ['member']],
            'user.suspend' => $ada,
            'user.activate' => $ada,
            'user.set_password' => [...$ada, 'password' => 'correct-horse-42'],
            'user.remove_password' => $ada,
            'user.delete' => [...$ada, 'reassign' => 'kim'],
            'role.create' => [...$translator, 'label' => 'Übersetzer', 'from' => 'editor'],
            'role.rename' => [...$translator, 'label' => 'Translator'],
            'role.delete' => $translator,
            'role.grant' => [...$translator, 'abilities' => ['content.post.edit_any', 'content.page.edit_any']],
            'role.revoke' => [...$translator, 'abilities' => ['content.post.edit_any']],
            'setting.set' => ['setting' => 'site.title', 'value' => 'It\'s news'],
            'theme.use' => ['theme' => 'acme-dark'],
            'extension.enable' => $extensions,
            'extension.disable' => $extensions,
            'extension.purge' => $extensions,
        ];

        $cases = [];

        foreach ($samples as $id => $arguments) {
            $cases[$id] = [$id, $arguments];
        }

        return $cases;
    }

    public function testEveryCoreActionHasASample(): void
    {
        $core = array_values(array_filter($this->actions->ids(), fn (string $id): bool => 'core' === $this->actions->owner($id)));

        self::assertEqualsCanonicalizing($core, array_keys(self::samples()));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('samples')]
    public function testItsCommandAcceptsTheCommandLineItWrites(string $id, array $arguments): void
    {
        $definition = $this->actions->definition($id);
        $line = $this->actions->commandLine($id, $arguments);

        self::assertNotNull($line);
        self::assertStringStartsWith('bin/console '.$definition->command.' ', $line.' ');

        $application = new Application(self::$kernel ?? self::bootKernel());
        $input = new StringInput(substr($line, \strlen('bin/console '.$definition->command)));
        $command = $application->find((string) $definition->command);
        $input->bind($command->getDefinition());
        $input->validate();

        foreach ($definition->parameters as $parameter) {
            if ($parameter->secret && \is_string($arguments[$parameter->name] ?? null)) {
                self::assertStringNotContainsString($arguments[$parameter->name], $line, 'Secrets are never on a command line.');
            }
        }
    }

    public function testWhoMayDoIt(): void
    {
        $post = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Actions'));
        $publish = ['content' => $post->id, 'transition' => Status::PUBLISH];

        $this->signIn($this->user('member'));
        self::assertSame('Publishing takes the right to publish; ask someone who has it.', $this->actions->denied('content.transition', $publish));
        self::assertSame('Managing users takes the right to manage them; ask an admin.', $this->actions->denied('user.suspend', ['user' => 'nobody']));

        try {
            $this->actions->run('content.transition', $publish);
            self::fail('A member published.');
        } catch (ActionDeniedException $e) {
            self::assertSame(['content.transition', 'Publishing takes the right to publish; ask someone who has it.'], [$e->action, $e->getMessage()]);
        }

        $this->signIn($this->user('editor'));
        self::assertNull($this->actions->denied('content.transition', $publish));
        $published = $this->actions->run('content.transition', $publish);

        self::assertInstanceOf(Content::class, $published);
        self::assertSame(Status::PUBLISHED, $published->status);
        // Recorded as the command the action writes.
        $recorded = self::getContainer()->get(AuditLog::class)->recordedNow();
        self::assertSame($this->actions->commandLine('content.transition', $publish), end($recorded)->command ?? null);
    }

    public function testTheyDoWhatTheySay(): void
    {
        $admin = $this->user('admin');
        $this->signIn($admin);
        $kim = $this->user('member');

        $created = $this->actions->run('content.create', ['type' => 'post', 'title' => 'About']);
        self::assertInstanceOf(Content::class, $created);
        self::assertSame([Status::DRAFT, $admin->id], [$created->status, $created->authorId], 'Written by who created it.');
        $renamed = $this->actions->run('content.update', ['content' => $created->id, 'title' => 'About us', 'excerpt' => 'Who we are.']);
        self::assertInstanceOf(Content::class, $renamed);
        self::assertSame(['About us', 'about', 'Who we are.'], [$renamed->title, $renamed->slug, $renamed->excerpt]);
        $this->actions->run('content.trash', ['content' => $created->id]);
        $this->actions->run('content.restore', ['content' => $created->id]);

        $this->actions->run('user.update', ['user' => $kim->username, 'name' => 'Kim Ono']);
        $this->actions->run('user.change_roles', ['user' => $kim->username, 'add' => ['editor'], 'remove' => ['member']]);
        $this->actions->run('user.suspend', ['user' => $kim->username]);
        $kim = self::getContainer()->get(UserRepository::class)->find($kim->id);
        self::assertSame(['Kim Ono', ['editor'], User::SUSPENDED], [$kim?->displayName, $kim?->roles, $kim?->status]);

        $this->actions->run('role.create', ['role' => 'translator', 'label' => 'Translator']);
        $this->actions->run('role.grant', ['role' => 'translator', 'abilities' => ['content.post.edit_any']]);
        $this->actions->run('role.rename', ['role' => 'translator', 'label' => 'Übersetzer']);
        self::assertSame(['translator' => 'Übersetzer'], self::getContainer()->get(Permissions::class)->siteRoles());
        self::assertSame(['content.post.edit_any'], self::getContainer()->get(Permissions::class)->abilitiesOf('translator'));

        $this->actions->run('setting.set', ['setting' => 'quire.content.per_page', 'value' => 25]);
        self::assertSame(25, self::getContainer()->get(Settings::class)->get('quire.content.per_page'));
        $this->actions->run('setting.set', ['setting' => 'quire.content.per_page', 'default' => true]);
        self::assertSame(10, self::getContainer()->get(Settings::class)->get('quire.content.per_page'));
    }

    public function testChangesGoThroughAReview(): void
    {
        $author = $this->user('editor');
        $reviewer = $this->user('editor');
        $post = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Draws'));
        $post = self::getContainer()->get(ContentWriter::class)->transition($post->id, $post->version, Status::PUBLISH);
        $pending = self::getContainer()->get(PendingRevisionWriter::class)->create($post->id, $author->id);
        self::getContainer()->get(PendingRevisionWriter::class)->update(new UpdatePendingRevision($pending->id, 1)->title('Draws and fixtures'));
        $changes = ['content' => $post->id, 'pending_revision' => $pending->id];

        $this->signIn($author);
        $this->actions->run('content.submit_changes', $changes);
        self::assertSame('Someone else approves your changes.', $this->actions->denied('content.review_changes', [...$changes, 'verdict' => 'approve']));

        $this->signIn($reviewer);
        self::assertNull($this->actions->denied('content.discard_changes', $changes), 'Who may publish may discard.');

        try {
            $other = $this->actions->run('content.create', ['type' => 'post', 'title' => 'Other']);
            self::assertInstanceOf(Content::class, $other);
            $this->actions->run('content.discard_changes', ['content' => $other->id, 'pending_revision' => $pending->id]);
            self::fail('Changes were discarded through another item.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('The changes are to another item.', $e->getMessage());
        }

        $this->actions->run('content.review_changes', [...$changes, 'verdict' => 'approve']);
        $published = $this->actions->run('content.publish_changes', $changes);

        self::assertInstanceOf(Content::class, $published);
        self::assertSame('Draws and fixtures', $published->title);
    }

    public function testItsArgumentsAreChecked(): void
    {
        try {
            $this->actions->run('content.transition', ['transition' => 'fly']);
            self::fail('Invalid arguments were accepted.');
        } catch (InvalidArgumentsException $e) {
            self::assertSame(['content', 'transition'], array_keys($e->errors));
            self::assertStringStartsWith('Give one of publish, schedule, unpublish, archive', $e->errors['transition']);
        }

        $this->expectException(UnknownActionException::class);
        $this->expectExceptionMessageIsOrContains('There is no action "content.fly". Is the extension that adds it enabled?');

        $this->actions->run('content.fly', []);
    }

    private function user(string $role): User
    {
        return self::getContainer()->get(UserWriter::class)->create(new CreateUser(ucfirst($role), $role.'-'.bin2hex(random_bytes(3)).'@example.com', roles: [$role]));
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
