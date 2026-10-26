<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\ContentWriter;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Revision\CommentAnchor;
use Quire\Content\Revision\CommentThread;
use Quire\Content\Revision\PendingRevision;
use Quire\Content\Revision\PendingRevisionComments;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\User\CreateUser;
use Quire\User\User;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Conversations about changes that aren't published yet, pointing at their text as it moves.
 */
final class PendingRevisionCommentsTest extends KernelTestCase
{
    private const string BODY = "Sixteen teams, four groups.\n\n## Group A\n\nHosts FC Harbour open against Northside on Friday.\n";

    private Connection $connection;

    private PendingRevisionComments $comments;

    private PendingRevisionWriter $writer;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->comments = self::getContainer()->get(PendingRevisionComments::class);
        $this->writer = self::getContainer()->get(PendingRevisionWriter::class);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testThreadsWithRepliesPointingAtText(): void
    {
        [$grace, $ada] = [$this->user('Grace'), $this->user('Ada')];
        $pending = $this->changes($grace);

        $general = $this->comments->add($pending->id, 'Added the stream numbers.', $grace->id);
        $anchored = $this->comments->add($pending->id, '"United" is the full name now, right?', $ada->id, new CommentAnchor('body', 'Northside', 5));
        $reply = $this->comments->add($pending->id, ' Yes, since August. ', $grace->id, replyTo: $anchored->id);

        self::assertSame(['body', 'Northside', 5, $pending->version], [$anchored->anchor?->property, $anchored->anchor?->quote, $anchored->anchor?->line, $anchored->anchor?->version]);
        self::assertSame('Yes, since August.', $reply->body, 'Trimmed.');

        $threads = $this->comments->threads($pending);
        self::assertSame([[$general->id, [], null, false], [$anchored->id, [$reply->id], 5, false]], $this->summary($threads));
        self::assertSame([$pending->id => 2], $this->comments->openCounts([$pending->id]));

        $this->comments->resolve($anchored->id, $grace->id);
        self::assertSame([$pending->id => 1], $this->comments->openCounts([$pending->id]));
        self::assertSame($grace->id, $this->comments->get($anchored->id)->resolvedBy);
        self::assertFalse($this->comments->resolve($anchored->id, $ada->id, resolved: false)->isResolved(), 'Opened again.');
    }

    public function testTheTextIsFoundAgainAsTheChangesAreEdited(): void
    {
        $pending = $this->changes($this->user('Grace'));
        $comment = $this->comments->add($pending->id, 'Full name?', anchor: new CommentAnchor('body', 'Northside', 5));
        $title = $this->comments->add($pending->id, 'Shorter?', anchor: new CommentAnchor('title', 'all groups'));

        // Two lines added above: it moves with its text.
        $pending = $this->edit($pending, "Updated on Saturday.\n\n".self::BODY);
        self::assertSame([[$comment->id, [], 7, false], [$title->id, [], null, false]], $this->summary($this->comments->threads($pending)), 'The title has no lines.');

        // The same words further down too: the nearest wins.
        $pending = $this->edit($pending, "Updated on Saturday.\n\n".self::BODY."\nNorthside travel on Thursday.\n");
        self::assertSame(7, $this->comments->threads($pending)[0]->line);

        // Gone: outdated, and still shown with what it quoted.
        $pending = $this->edit($pending, 'Sixteen teams.');
        [$thread] = $this->comments->threads($pending);
        self::assertSame([null, true, 'Northside'], [$thread->line, $thread->outdated, $thread->comment->anchor?->quote]);
    }

    public function testWhatIsntAComment(): void
    {
        $pending = $this->changes($this->user('Grace'));
        $first = $this->comments->add($pending->id, 'First.');
        $reply = $this->comments->add($pending->id, 'Reply.', replyTo: $first->id);

        foreach ([
            'body' => fn (): \Quire\Content\Revision\Comment => $this->comments->add($pending->id, '   '),
            'anchor' => fn (): \Quire\Content\Revision\Comment => $this->comments->add($pending->id, 'Hm.', anchor: new CommentAnchor('body', 'Southside')),
            'reply_to' => fn (): \Quire\Content\Revision\Comment => $this->comments->add($pending->id, 'Deeper.', replyTo: $reply->id),
        ] as $property => $add) {
            try {
                $add();
                self::fail('Accepted an invalid comment on '.$property.'.');
            } catch (InvalidContentException $e) {
                self::assertArrayHasKey($property, $e->violations);
            }
        }

        $this->expectExceptionMessageIsOrContains('Threads are resolved by their first comment.');
        $this->comments->resolve($reply->id);
    }

    public function testCommentsAreRecordedAndGoWithTheChanges(): void
    {
        $grace = $this->user('Grace');
        $pending = $this->changes($grace);
        $comment = $this->comments->add($pending->id, 'It\'s "Northside United".', $grace->id, new CommentAnchor('body', 'Northside', 5));
        $this->comments->resolve($comment->id, $grace->id);

        $entries = array_values(array_filter(self::getContainer()->get(AuditLog::class)->recordedNow(), static fn (AuditEntry $entry): bool => \in_array($entry->action, ['content.changes_commented', 'content.comment_resolved'], true)));
        $commands = array_map(static fn (AuditEntry $entry): ?string => $entry->command, $entries);

        self::assertSame(['content.changes_commented', 'content.comment_resolved'], array_map(static fn (AuditEntry $entry): string => $entry->action, $entries));
        self::assertSame([$grace->id, $grace->id], array_map(static fn (AuditEntry $entry): ?string => $entry->actorId, $entries));

        self::assertContains(\sprintf("bin/console quire:content:comments %s %s --add='It'\\''s \"Northside United\".' --property=body --quote=Northside --line=5", $pending->contentId, $pending->id), $commands);
        self::assertContains(\sprintf('bin/console quire:content:comments %s %s --resolve=%s', $pending->contentId, $pending->id, $comment->id), $commands);

        $this->writer->discard($pending->id);
        self::assertNull($this->comments->find($comment->id), 'Discarded with the changes.');
    }

    private function changes(User $author): PendingRevision
    {
        $post = self::getContainer()->get(ContentWriter::class)->create(new CreateContent('post', 'Spring draw: all groups', body: self::BODY));
        $post = self::getContainer()->get(ContentWriter::class)->transition($post->id, $post->version, 'publish');
        $pending = $this->writer->create($post->id, $author->id);

        return $this->writer->update(new UpdatePendingRevision($pending->id, $pending->version)->title('Spring draw: all groups, final'));
    }

    private function edit(PendingRevision $pending, string $body): PendingRevision
    {
        $this->writer->update(new UpdatePendingRevision($pending->id, $pending->version)->body($body));

        return self::getContainer()->get(PendingRevisionRepository::class)->get($pending->id);
    }

    private function user(string $name): User
    {
        return self::getContainer()->get(UserWriter::class)->create(new CreateUser($name, strtolower($name).'-'.bin2hex(random_bytes(3)).'@example.com'));
    }

    /**
     * @param list<CommentThread> $threads
     *
     * @return list<array{string, list<string>, ?int, bool}>
     */
    private function summary(array $threads): array
    {
        return array_map(static fn (CommentThread $thread): array => [$thread->comment->id, array_map(static fn (\Quire\Content\Revision\Comment $reply): string => $reply->id, $thread->replies), $thread->line, $thread->outdated], $threads);
    }
}
