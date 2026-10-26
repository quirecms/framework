<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Action\Actions;
use Quire\Action\Exception\ActionDeniedException;
use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Audit\AuditQuery;
use Quire\Audit\AuditUndo;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Settings\Settings;
use Quire\User\CreateUser;
use Quire\User\Role;
use Quire\User\User;
use Quire\User\UserRepository;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Undoing what the audit log records, while nothing changed it since; and finding entries by text,
 * with how many there are per day and per action.
 */
final class AuditUndoTest extends KernelTestCase
{
    private Connection $connection;

    private Actions $actions;

    private AuditUndo $undo;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->actions = self::getContainer()->get(Actions::class);
        $this->undo = self::getContainer()->get(AuditUndo::class);
        $this->signIn($this->user('Ada', Role::ADMIN));
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testPublishingIsUndoneByTheTransitionBack(): void
    {
        $post = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Spring draw'));
        $this->actions->run('content.transition', ['content' => $post->id, 'transition' => 'publish']);
        $published = $this->latest('content.transitioned');

        self::assertTrue($this->undo->canUndo($published));
        $plan = $this->undo->undo($published);
        self::assertSame(['content.transition', 'unpublish'], [$plan->action, $plan->arguments['transition']]);
        self::assertSame(Status::DRAFT, self::getContainer()->get(ContentRepository::class)->find($post->id)?->status);
        self::assertSame('unpublish', $this->latest('content.transitioned')->details['transition'] ?? null, 'Recorded like any action.');
        self::assertNull($this->undo->plan($published), 'Once: it isn\'t as it left it any more.');
    }

    public function testOnlyWhileNothingChangedItSince(): void
    {
        $post = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Venue'));
        $this->actions->run('content.trash', ['content' => $post->id]);
        $trashed = $this->latest('content.trashed');
        self::assertSame('content.restore', $this->undo->plan($trashed)?->action);

        $this->actions->run('content.restore', ['content' => $post->id]);
        self::assertNull($this->undo->plan($trashed), 'Restored since.');
        self::assertSame('content.trash', $this->undo->plan($this->latest('content.restored'))?->action, 'The latest is.');

        $kim = $this->user('Kim', Role::EDITOR);
        $this->actions->run('user.change_roles', ['user' => $kim->id, 'add' => [Role::ADMIN], 'remove' => [Role::EDITOR]]);
        $changed = $this->latest('user.roles_changed');
        self::assertSame(['user.change_roles', [Role::EDITOR], [Role::ADMIN]], [$this->undo->plan($changed)?->action, $this->undo->plan($changed)->arguments['add'] ?? null, $this->undo->plan($changed)->arguments['remove'] ?? null]);
        $this->undo->undo($changed);
        self::assertSame([Role::EDITOR], self::getContainer()->get(UserRepository::class)->find($kim->id)?->roles);
    }

    public function testRolesAndSettingsBackToWhatTheyWere(): void
    {
        $this->actions->run('role.grant', ['role' => Role::EDITOR, 'abilities' => ['quire.settings.manage']]);
        $this->undo->undo($this->latest('role.abilities_changed'));
        self::assertNotContains(Role::EDITOR, self::getContainer()->get(Permissions::class)->rolesWith('quire.settings.manage'));

        $this->actions->run('setting.set', ['setting' => 'site.title', 'value' => 'Tournament News']);
        $before = self::getContainer()->get(Settings::class)->getString('site.title');
        $this->actions->run('setting.set', ['setting' => 'site.title', 'value' => 'Spring Cup']);
        $this->undo->undo($this->latest('setting.changed'));
        self::assertSame($before, self::getContainer()->get(Settings::class)->getString('site.title'));
    }

    public function testWhatCantBeTakenBackAndWhoMayNot(): void
    {
        $post = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Gone'));
        $this->actions->run('content.trash', ['content' => $post->id]);
        $this->actions->run('content.purge', ['content' => $post->id]);
        self::assertNull($this->undo->plan($this->latest('content.purged')));

        $kim = $this->user('Kim', Role::EDITOR);
        $this->actions->run('user.suspend', ['user' => $kim->id]);
        $suspended = $this->latest('user.suspended');
        self::assertNotNull($this->undo->plan($suspended));

        $this->signIn($this->user('Edna', Role::EDITOR));
        self::assertFalse($this->undo->canUndo($suspended), 'Letting people in takes managing users.');
        $this->expectException(ActionDeniedException::class);
        $this->undo->undo($suspended);
    }

    public function testForAPageOfEntriesAtOnceTheSameAsOneByOne(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $draw = $writer->create(new CreateContent('post', 'Spring draw'));
        $venue = $writer->create(new CreateContent('post', 'Venue'));
        $kim = $this->user('Kim', Role::EDITOR);
        $this->actions->run('content.transition', ['content' => $draw->id, 'transition' => 'publish']);
        $this->actions->run('content.trash', ['content' => $venue->id]);
        $this->actions->run('content.restore', ['content' => $venue->id]);
        $this->actions->run('user.suspend', ['user' => $kim->id]);
        $this->actions->run('setting.set', ['setting' => 'site.title', 'value' => 'Spring Cup']);
        // Only this test's: tests that commit leave entries behind.
        $entries = array_values(array_filter(self::getContainer()->get(AuditLog::class)->find(perPage: 50)->entries, static fn (AuditEntry $entry): bool => \in_array($entry->subjectId, [$draw->id, $venue->id, $kim->id, 'site.title'], true)));
        $oneByOne = array_keys(array_filter(array_combine(array_map(static fn (AuditEntry $entry): string => $entry->id, $entries), array_map($this->undo->canUndo(...), $entries))));

        self::assertCount(4, $oneByOne, 'Publishing, restoring, suspending and the setting; trashing was restored since.');
        self::assertEqualsCanonicalizing($oneByOne, array_keys($this->undo->undoable($entries)));

        // Only what the person may do: an editor may take publishing back, not suspending.
        $this->signIn($this->user('Grace', Role::EDITOR));
        $oneByOne = array_keys(array_filter(array_combine(array_map(static fn (AuditEntry $entry): string => $entry->id, $entries), array_map($this->undo->canUndo(...), $entries))));
        self::assertCount(2, $oneByOne);
        self::assertEqualsCanonicalizing($oneByOne, array_keys($this->undo->undoable($entries)));
    }

    public function testFindingByTextAndCounting(): void
    {
        $log = self::getContainer()->get(AuditLog::class);
        $log->record('acme.order_refunded', 'acme.order', '1042', 'Order 1042');
        $log->record('acme.order_refunded', 'acme.order', '1043', 'Order 1043');
        $log->record('acme.note_added', 'acme.order', '1043', 'Order 1043');

        self::assertSame(['Order 1043', 'Order 1043'], array_map(static fn (AuditEntry $entry): ?string => $entry->subjectLabel, $log->find(new AuditQuery(action: 'acme.', text: '1043'))->entries));
        self::assertCount(3, $log->find(new AuditQuery(action: 'acme.', text: 'order'))->entries, 'Labels and actions.');
        self::assertCount(1, $log->find(new AuditQuery(action: 'acme.', text: 'refund money', textActions: ['acme.note_added']))->entries, 'Actions an interface says with the words.');

        self::assertSame(['acme.order_refunded' => 2, 'acme.note_added' => 1], $log->countByAction(new AuditQuery(action: 'acme.')));
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        self::assertSame([$today => 3], $log->countByDay(new AuditQuery(action: 'acme.'), new \DateTimeZone('Europe/Berlin')));
    }

    public function testFromTheCommandLine(): void
    {
        $post = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Draw'));
        $this->actions->run('content.transition', ['content' => $post->id, 'transition' => 'publish']);
        $entry = $this->latest('content.transitioned');
        $command = new CommandTester(new Application(self::$kernel ?? throw new \LogicException('No kernel.'))->find('quire:audit:undo'));

        self::assertSame(0, $command->execute(['entry' => $entry->id, '--dry-run' => true]));
        self::assertSame('bin/console quire:content:transition '.$post->id.' unpublish', trim($command->getDisplay()));
        self::assertSame(Status::PUBLISHED, self::getContainer()->get(ContentRepository::class)->find($post->id)?->status, 'Only said.');

        self::assertSame(0, $command->execute(['entry' => $entry->id]));
        self::assertSame(1, $command->execute(['entry' => $entry->id]), 'Once.');
        self::assertStringContainsString('can\'t be undone', $command->getDisplay());
    }

    private function latest(string $action): AuditEntry
    {
        return self::getContainer()->get(AuditLog::class)->find(new AuditQuery(action: $action), perPage: 1)->entries[0] ?? throw new \LogicException('Nothing recorded as '.$action);
    }

    private function user(string $name, string $role): User
    {
        return self::getContainer()->get(UserWriter::class)->create(new CreateUser($name, strtolower($name).'-'.bin2hex(random_bytes(3)).'@example.com', roles: [$role]));
    }

    private function signIn(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }
}
