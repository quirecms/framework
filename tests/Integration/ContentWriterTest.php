<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentWriter;
use Quire\Content\Event\ContentCreated;
use Quire\Content\Event\ContentCreating;
use Quire\Content\Event\ContentDeleted;
use Quire\Content\Event\ContentPublished;
use Quire\Content\Event\ContentPublishing;
use Quire\Content\Event\ContentPurged;
use Quire\Content\Event\ContentRestored;
use Quire\Content\Event\ContentStatusChanged;
use Quire\Content\Event\ContentUnpublished;
use Quire\Content\Event\ContentUpdated;
use Quire\Content\Event\ContentUpdating;
use Quire\Content\Exception\ContentConflictException;
use Quire\Content\Exception\ContentOperationCancelledException;
use Quire\Content\Exception\InvalidContentException;
use Quire\Content\Exception\TransitionNotAllowedException;
use Quire\Content\Revision\RevisionRepository;
use Quire\Content\Status;
use Quire\Content\Workflow\ContentSubject;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

final class ContentWriterTest extends KernelTestCase
{
    private Connection $connection;

    private ContentWriter $writer;

    /** @var list<object> */
    private array $events = [];

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->writer = $container->get(ContentWriter::class);

        $dispatcher = $container->get(EventDispatcherInterface::class);

        foreach ([ContentCreated::class, ContentUpdated::class, ContentStatusChanged::class, ContentPublished::class, ContentUnpublished::class, ContentDeleted::class, ContentRestored::class, ContentPurged::class] as $event) {
            $dispatcher->addListener($event, function (object $event): void {
                $this->events[] = $event;
            });
        }
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testCreatesADraftWithARevision(): void
    {
        $post = $this->writer->create(new CreateContent('post', '  Hello World  ', body: 'Hi *there*', excerpt: 'Short', fields: ['mood' => 'happy'], revisionMessage: 'First draft'));

        self::assertSame(['post', Status::DRAFT, 'en', 'Hello World', 'hello-world', 1], [$post->type, $post->status, $post->locale, $post->title, $post->slug, $post->version]);
        self::assertSame('happy', $post->field('mood'));
        self::assertNull($post->publishedAt);
        self::assertFalse($post->isPublic());
        self::assertSame('english', $this->connection->fetchOne('SELECT search_config::text FROM content WHERE id = ?', [$post->id]));

        $revisions = $this->revisions($post);
        self::assertCount(1, $revisions);
        self::assertSame([1, 'Hello World', 'Hi *there*', 'First draft'], [$revisions[0]->contentVersion, $revisions[0]->title, $revisions[0]->body, $revisions[0]->message]);

        self::assertCount(1, $this->recorded());
        self::assertInstanceOf(ContentCreated::class, $this->recorded()[0]);
        self::assertSame($post->id, $this->recorded()[0]->content->id);
    }

    public function testNewContentUsesTheSiteLanguage(): void
    {
        self::getContainer()->get(Settings::class)->set('site.locale', 'de');

        $page = $this->writer->create(new CreateContent('page', 'Über uns'));

        self::assertSame(['de', 'ueber-uns'], [$page->locale, $page->slug], 'Transliterated for German.');
        self::assertSame('german', $this->connection->fetchOne('SELECT search_config::text FROM content WHERE id = ?', [$page->id]));
    }

    public function testGeneratedSlugsAreMadeUnique(): void
    {
        $slugs = array_map(fn (): string => $this->writer->create(new CreateContent('post', 'Hello'))->slug, range(1, 3));
        $slugs[] = $this->writer->create(new CreateContent('page', 'Hello'))->slug;
        $slugs[] = $this->writer->create(new CreateContent('post', '¡¡¡'))->slug;

        self::assertSame(['hello', 'hello-2', 'hello-3', 'hello', 'untitled'], $slugs);
    }

    public function testRequestedSlugsMustBeFree(): void
    {
        $this->writer->create(new CreateContent('post', 'Hello'));

        $violations = $this->violations(fn (): Content => $this->writer->create(new CreateContent('post', 'Another', slug: 'hello')));

        self::assertSame(['slug' => 'Another item here already uses the slug "hello".'], $violations);
    }

    public function testCollectsEveryViolation(): void
    {
        // @phpstan-ignore argument.type (a list instead of an object of fields, on purpose)
        $violations = $this->violations(fn (): Content => $this->writer->create(new CreateContent('page', ' ', bodyFormat: 'Mark Down', slug: 'Bad Slug', locale: 'english', excerpt: 'x', fields: ['a', 'b'])));

        self::assertSame(['title', 'locale', 'body_format', 'excerpt', 'fields', 'slug'], array_keys($violations));
        self::assertSame('Pages have no excerpt.', $violations['excerpt']);

        self::assertSame(['type' => 'No content type "product" is registered.'], $this->violations(fn (): Content => $this->writer->create(new CreateContent('product', 'X'))));
        self::assertSame(['parent_id' => 'Posts aren\'t hierarchical, so they can\'t have a parent.'], $this->violations(fn (): Content => $this->writer->create(new CreateContent('post', 'X', parentId: '0192b6a0-0000-7000-8000-000000000000'))));
    }

    public function testCreatingListenersCanChangeOrCancel(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ContentCreating::class, static function (ContentCreating $event): void {
            if ('Spam' === $event->command->title) {
                $event->cancel('No spam, please.');
            }

            $event->command->fields['featured'] = true;
        });

        self::assertTrue($this->writer->create(new CreateContent('post', 'Hello'))->field('featured'));

        try {
            $this->writer->create(new CreateContent('post', 'Spam'));
            self::fail('The listener cancelled the creation.');
        } catch (ContentOperationCancelledException $e) {
            self::assertSame('No spam, please.', $e->getMessage());
        }

        self::assertEquals(1, $this->connection->fetchOne('SELECT COUNT(*) FROM content'));
        self::assertCount(1, $this->recorded(), 'No ContentCreated for the cancelled item.');
    }

    public function testUpdateKeepsTheSlugAndSavesARevision(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));
        $this->events = [];

        $updated = $this->writer->update(new UpdateContent($post->id, 1, revisionMessage: 'Better title')->title('Hello again')->body('New body'));

        self::assertSame(['Hello again', 'hello', 'New body', 2], [$updated->title, $updated->slug, $updated->body, $updated->version]);
        self::assertSame([2, 1], array_map(static fn (\Quire\Content\Revision\Revision $r): int => $r->contentVersion, $this->revisions($post)));
        self::assertSame('Better title', $this->revisions($post)[0]->message);

        self::assertCount(1, $this->recorded());
        self::assertInstanceOf(ContentUpdated::class, $this->recorded()[0]);
        self::assertSame(['Hello', 'Hello again'], [$this->recorded()[0]->before->title, $this->recorded()[0]->content->title]);
    }

    public function testConcurrentEditsConflict(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));
        $this->writer->update(new UpdateContent($post->id, 1)->title('Alice was here'));

        try {
            $this->writer->update(new UpdateContent($post->id, 1)->title('Bob was here'));
            self::fail('Bob edited an old version.');
        } catch (ContentConflictException $e) {
            self::assertSame([1, 2], [$e->expectedVersion, $e->currentVersion]);
        }

        self::assertSame('Alice was here', $this->get($post)->title);
    }

    public function testUnchangedValuesSaveNothing(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello', fields: ['rating' => 1, 'mood' => 'sad']));
        $this->events = [];

        $same = $this->writer->update(new UpdateContent($post->id, 1)->title('Hello')->fields(['mood' => 'sad', 'featured' => false, 'rating' => 1]));

        self::assertSame(1, $same->version);
        self::assertSame([], $this->recorded());
        self::assertCount(1, $this->revisions($post));
    }

    public function testFieldChanges(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello', fields: ['rating' => 1, 'color' => 'red']));

        $updated = $this->writer->update(new UpdateContent($post->id, 1)->field('rating', 2)->field('color', null)->field('size', 'L'));

        self::assertSame(2, $updated->field('rating'));
        self::assertSame('L', $updated->field('size'));
        self::assertArrayNotHasKey('color', $updated->fields);
    }

    public function testOnlyTextChangesCreateRevisions(): void
    {
        $page = $this->writer->create(new CreateContent('page', 'About'));

        $moved = $this->writer->update(new UpdateContent($page->id, 1)->position(5));

        self::assertSame([2, 5], [$moved->version, $moved->position]);
        self::assertCount(1, $this->revisions($page));
    }

    public function testUpdatingListenersCanAddChanges(): void
    {
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(ContentUpdating::class, static function (ContentUpdating $event): void {
            $event->command->field('subtitle', 'Edited');
        });
        $post = $this->writer->create(new CreateContent('post', 'Hello'));

        self::assertSame('Edited', $this->writer->update(new UpdateContent($post->id, 1)->title('Changed'))->field('subtitle'));
    }

    public function testPagesFormATree(): void
    {
        $docs = $this->writer->create(new CreateContent('page', 'Docs'));
        $intro = $this->writer->create(new CreateContent('page', 'Intro', parentId: $docs->id));
        $setup = $this->writer->create(new CreateContent('page', 'Setup', parentId: $docs->id));
        $german = $this->writer->create(new CreateContent('page', 'Doku', locale: 'de'));

        self::assertSame([0, 0, 1], [$docs->position, $intro->position, $setup->position], 'Appended after the last sibling.');
        self::assertSame('intro', $this->writer->create(new CreateContent('page', 'Intro'))->slug, 'Slugs are unique among siblings only.');

        self::assertSame(['parent_id' => 'An item can\'t be moved below itself.'], $this->violations(fn (): Content => $this->writer->update(new UpdateContent($docs->id, 1)->parent($intro->id))));
        self::assertSame(['parent_id' => 'An item can\'t be moved below itself.'], $this->violations(fn (): Content => $this->writer->update(new UpdateContent($docs->id, 1)->parent($docs->id))));
        self::assertSame(['parent_id' => 'The parent must be in the same language (en).'], $this->violations(fn (): Content => $this->writer->update(new UpdateContent($intro->id, 1)->parent($german->id))));
        self::assertSame(['slug' => 'Another page here already uses the slug "intro".'], $this->violations(fn (): Content => $this->writer->update(new UpdateContent($intro->id, 1)->parent(null))));

        $moved = $this->writer->update(new UpdateContent($setup->id, 1)->parent(null));
        self::assertNull($moved->parentId);
    }

    public function testPublishAndUnpublish(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));
        $this->events = [];

        $published = $this->writer->transition($post->id, 1, Status::PUBLISH);

        self::assertSame([Status::PUBLISHED, 2], [$published->status, $published->version]);
        self::assertTrue($published->isPublic());
        self::assertEqualsWithDelta(time(), $published->publishedAt?->getTimestamp(), 5);
        self::assertSame([ContentStatusChanged::class, ContentPublished::class], array_map(get_class(...), $this->recorded()));

        $this->events = [];
        $archived = $this->writer->transition($post->id, 2, Status::ARCHIVE);
        self::assertSame([ContentStatusChanged::class, ContentUnpublished::class], array_map(get_class(...), $this->recorded()));

        $republished = $this->writer->transition($post->id, $archived->version, Status::PUBLISH);
        self::assertEquals($published->publishedAt, $republished->publishedAt, 'Republishing an archived item keeps its date.');
    }

    public function testPublishWithAnEarlierDate(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));

        $published = $this->writer->transition($post->id, 1, Status::PUBLISH, new \DateTimeImmutable('2020-05-01 12:00:00+00:00'));

        self::assertSame('2020-05-01', $published->publishedAt?->format('Y-m-d'));
    }

    public function testTransitionsMustExistFromTheCurrentStatus(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));

        $this->expectException(TransitionNotAllowedException::class);
        $this->expectExceptionMessageIsOrContains('A draft item can\'t take the "archive" transition. Possible now: publish, schedule, submit.');

        $this->writer->transition($post->id, 1, Status::ARCHIVE);
    }

    public function testGuardsCanBlockTransitions(): void
    {
        self::getContainer()->get(EventDispatcherInterface::class)->addListener('workflow.content.guard.publish', static function (GuardEvent $event): void {
            $subject = $event->getSubject();
            self::assertInstanceOf(ContentSubject::class, $subject);

            if ('' === $subject->content->body) {
                $event->setBlocked(true, 'Write something first.');
            }
        });
        $post = $this->writer->create(new CreateContent('post', 'Hello'));

        try {
            $this->writer->transition($post->id, 1, Status::PUBLISH);
            self::fail('The guard blocked publishing.');
        } catch (TransitionNotAllowedException $e) {
            self::assertSame(['Write something first.'], $e->reasons);
            self::assertSame(['schedule', 'submit'], $e->enabled);
        }
    }

    public function testPublishingListenersCanCancel(): void
    {
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(ContentPublishing::class, static function (ContentPublishing $event): void {
            $event->cancel('Not on Fridays.');
        });
        $post = $this->writer->create(new CreateContent('post', 'Hello'));

        $this->expectException(ContentOperationCancelledException::class);
        $this->expectExceptionMessageIsOrContains('Not on Fridays.');

        $this->writer->transition($post->id, 1, Status::PUBLISH);
    }

    public function testExtensionsAddStatuses(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));

        $inReview = $this->writer->transition($post->id, 1, 'submit');
        $published = $this->writer->transition($post->id, $inReview->version, Status::PUBLISH);

        self::assertSame(['in_review', Status::PUBLISHED], [$inReview->status, $published->status]);
    }

    public function testScheduling(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));
        $page = $this->writer->create(new CreateContent('page', 'About'));
        $tomorrow = new \DateTimeImmutable('+1 day');

        self::assertSame(['published_at' => 'Scheduling needs a publication date in the future.'], $this->violations(fn (): Content => $this->writer->transition($post->id, 1, Status::SCHEDULE, new \DateTimeImmutable('-1 hour'))));
        self::assertSame(['published_at' => 'Pages can\'t be scheduled.'], $this->violations(fn (): Content => $this->writer->transition($page->id, 1, Status::SCHEDULE, $tomorrow)));
        self::assertSame(['published_at' => 'To publish at a future date, use the "schedule" transition.'], $this->violations(fn (): Content => $this->writer->transition($post->id, 1, Status::PUBLISH, $tomorrow)));

        $scheduled = $this->writer->transition($post->id, 1, Status::SCHEDULE, $tomorrow);
        self::assertSame(Status::SCHEDULED, $scheduled->status);
        self::assertFalse($scheduled->isPublic());
        self::assertTrue($scheduled->isPublic(new \DateTimeImmutable('+2 days')));
        self::assertSame([], $this->writer->publishDue(), 'Not due yet.');

        $this->connection->executeStatement("UPDATE content SET published_at = now() - interval '1 minute' WHERE id = ?", [$post->id]);
        $this->events = [];

        $due = $this->writer->publishDue();

        self::assertCount(1, $due);
        self::assertSame(Status::PUBLISHED, $due[0]->status);
        self::assertEqualsWithDelta(time() - 60, $due[0]->publishedAt?->getTimestamp(), 5, 'Keeps the scheduled date.');
        self::assertSame([ContentStatusChanged::class, ContentPublished::class], array_map(get_class(...), $this->recorded()));
    }

    public function testChangingThePublicationDate(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));

        self::assertArrayHasKey('published_at', $this->violations(fn (): Content => $this->writer->update(new UpdateContent($post->id, 1)->publishedAt(new \DateTimeImmutable('2020-01-01')))));

        $published = $this->writer->transition($post->id, 1, Status::PUBLISH);
        $backdated = $this->writer->update(new UpdateContent($post->id, $published->version)->publishedAt(new \DateTimeImmutable('2020-01-01 00:00:00+00:00')));

        self::assertSame('2020-01-01', $backdated->publishedAt?->format('Y-m-d'));
        self::assertArrayHasKey('published_at', $this->violations(fn (): Content => $this->writer->update(new UpdateContent($post->id, $backdated->version)->publishedAt(new \DateTimeImmutable('+1 day')))));
    }

    public function testTrashRestoreAndPurge(): void
    {
        $post = $this->writer->create(new CreateContent('post', 'Hello'));
        $this->events = [];

        $trashed = $this->writer->trash($post->id, 1);
        self::assertTrue($trashed->isTrashed());
        self::assertSame('hello', $this->writer->create(new CreateContent('post', 'Hello'))->slug, 'The trash frees the slug.');
        self::assertArrayHasKey('deleted_at', $this->violations(fn (): Content => $this->writer->update(new UpdateContent($post->id, $trashed->version)->title('X'))));

        $restored = $this->writer->restore($post->id);
        self::assertFalse($restored->isTrashed());
        self::assertSame('hello-2', $restored->slug, 'Its slug was taken meanwhile.');

        self::assertSame(['deleted_at' => 'Only items in the trash can be deleted permanently. Move it to the trash first.'], $this->violations(fn (): Content => $this->writer->purge($post->id)));

        $this->writer->trash($post->id, $restored->version);
        $this->writer->purge($post->id);

        self::assertNull(self::getContainer()->get(\Quire\Content\ContentRepository::class)->find($post->id));
        self::assertSame([], $this->revisions($post));
        self::assertSame(
            [ContentDeleted::class, ContentCreated::class, ContentRestored::class, ContentDeleted::class, ContentPurged::class],
            array_map(get_class(...), $this->recorded()),
        );
    }

    public function testParentsKeepTheirChildren(): void
    {
        $docs = $this->writer->create(new CreateContent('page', 'Docs'));
        $intro = $this->writer->create(new CreateContent('page', 'Intro', parentId: $docs->id));

        self::assertArrayHasKey('children', $this->violations(fn (): Content => $this->writer->trash($docs->id, 1)));

        $this->writer->trash($intro->id, 1);
        $trashedDocs = $this->writer->trash($docs->id, 1);

        self::assertArrayHasKey('parent_id', $this->violations(fn (): Content => $this->writer->restore($intro->id)), 'Restore the parent first.');
        self::assertArrayHasKey('children', $this->violations(fn (): Content => $this->writer->purge($docs->id)), 'A trashed child still counts.');
        self::assertTrue($trashedDocs->isTrashed());
    }

    public function testTranslations(): void
    {
        $english = $this->writer->create(new CreateContent('post', 'Hello'));
        $german = $this->writer->create(new CreateContent('post', 'Hallo', locale: 'de', translationOf: $english->id));
        $french = $this->writer->create(new CreateContent('post', 'Bonjour', locale: 'fr', translationOf: $german->id));

        self::assertSame($english->id, $german->translationGroupId);
        self::assertSame($english->id, $french->translationGroupId);
        self::assertSame($english->id, $this->get($english)->translationGroupId, 'The original joined the group.');
        self::assertSame(1, $this->get($english)->version);

        self::assertSame(['locale' => 'The item already has a translation in de.'], $this->violations(fn (): Content => $this->writer->create(new CreateContent('post', 'Servus', locale: 'de', translationOf: $english->id))));
        self::assertSame(['translation_of' => 'The item to translate is already in en.'], $this->violations(fn (): Content => $this->writer->create(new CreateContent('post', 'Hi', translationOf: $english->id))));
    }

    public function testRevisionsArePrunedAndRestorable(): void
    {
        self::getContainer()->get(Settings::class)->set('quire.content.revisions_keep', 2);
        $post = $this->writer->create(new CreateContent('post', 'One'));
        $post = $this->writer->update(new UpdateContent($post->id, $post->version)->title('Two'));
        $post = $this->writer->update(new UpdateContent($post->id, $post->version)->title('Three'));

        $revisions = $this->revisions($post);
        self::assertSame(['Three', 'Two'], array_map(static fn (\Quire\Content\Revision\Revision $r): string => $r->title, $revisions));

        $restored = $this->writer->restoreRevision($post->id, $revisions[1]->id, $post->version);

        self::assertSame(['Two', 4], [$restored->title, $restored->version]);
        self::assertStringStartsWith('Restored the revision of ', (string) $this->revisions($post)[0]->message);
        self::assertSame(['revision' => 'The item has no revision "nope".'], $this->violations(fn (): Content => $this->writer->restoreRevision($post->id, 'nope', $restored->version)));
    }

    /**
     * @return list<object> The "-ed" events dispatched since the last reset
     */
    private function recorded(): array
    {
        return $this->events;
    }

    /**
     * @return list<\Quire\Content\Revision\Revision>
     */
    private function revisions(Content $content): array
    {
        return self::getContainer()->get(RevisionRepository::class)->forContent($content->id);
    }

    private function get(Content $content): Content
    {
        return self::getContainer()->get(\Quire\Content\ContentRepository::class)->get($content->id);
    }

    /**
     * @param \Closure(): mixed $operation
     *
     * @return array<string, string>
     */
    private function violations(\Closure $operation): array
    {
        try {
            $operation();
        } catch (InvalidContentException $e) {
            return $e->violations;
        }

        self::fail('The operation was accepted.');
    }
}
