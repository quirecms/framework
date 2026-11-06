<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Quire\Action\Actions;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\RevisionRepository;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Permission\SiteRoles;
use Quire\Testing\AdminTestCase;
use Quire\Testing\TestInstallation;

/**
 * Every check about an item passes the item, so a rule decides on it (D-42, H-4): the test app's rule
 * (tests/Fixtures/extensions/hello, LockedContentRule) refuses everything on items titled "Locked",
 * admins included, as the docs' own example "nobody edits a locked item" does. And restoring a version
 * takes what saving takes (M-02).
 */
final class ItemAccessTest extends AdminTestCase
{
    /** With the fixture extension whose rule locks items */
    #[\Override]
    protected static array $extensions = ['quire-admin', 'quire-fixture-hello'];

    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }

    public function testNothingChangesALockedItemThroughTheAdmin(): void
    {
        $writer = $this->service(ContentWriter::class);
        $locked = $this->content('post', 'Locked')->body('Kept as it is.')->published()->create();
        $locked = $writer->update(new UpdateContent($locked->id, $locked->version)->excerpt('Once.'));
        $pending = $this->changes($locked)->by($this->createUser('Eda', ['editor']))->title('Unlocked')->submitted()->create();
        $revision = $this->service(RevisionRepository::class)->forContent($locked->id)[0];
        $this->signIn('admin');
        $editor = $this->csrfToken('quire_admin_content_editor');
        $base = '/admin/content/post/'.$locked->id;

        // The editor and saving
        $this->client->request('GET', $base);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $base, ['_token' => $editor, 'title' => 'Mine now', 'version' => $locked->version]);
        self::assertResponseStatusCodeSame(403);

        // The changes: reviewed, published or discarded
        foreach (['approve', 'request_changes', 'publish', 'discard'] as $intent) {
            $this->client->request('POST', $base.'/changes/'.$pending->id, ['_token' => $editor, 'intent' => $intent, 'version' => $pending->version, 'message' => 'Sure.']);
            self::assertResponseRedirects($base.'/changes/'.$pending->id, null, $intent);
        }

        // The history's restore, and the address
        $this->client->request('POST', $base.'/restore', ['_token' => $editor, 'revision' => $revision->id, 'version' => $locked->version]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $base.'/place', ['_token' => $editor, 'slug' => 'moved', 'version' => $locked->version]);
        self::assertResponseStatusCodeSame(403);

        // The list offers nothing for it, and what's sent anyway is refused for it alone
        $open = $this->content('post', 'Open')->published()->create();
        $crawler = $this->client->request('GET', '/admin/content/post');
        self::assertCount(0, $crawler->filter(\sprintf('select[name=row] option[value$="|%s"]', $locked->id)), 'No action on its row.');
        $list = (string) $crawler->filter('form.content-list input[name=_token]')->attr('value');

        foreach (['transition:unpublish', 'trash'] as $action) {
            $this->client->request('POST', '/admin/content/post', ['_token' => $list, 'action' => $action, 'ids' => [$locked->id, $open->id]]);
            $this->client->followRedirect();
            self::assertAnySelectorTextContains('.q-notice--error', '“Locked”', $action);
            $open = $this->restore($open);
        }

        $item = $this->service(ContentRepository::class)->get($locked->id);
        self::assertSame([$locked->version, Status::PUBLISHED, 'Locked', null], [$item->version, $item->status, $item->title, $item->deletedAt]);
        self::assertNotNull($this->service(PendingRevisionRepository::class)->find($pending->id), 'Its changes are still there, unpublished.');
    }

    public function testCoreActionsRefuseALockedItemToo(): void
    {
        // The intent bar, undo and the command line act through them
        $admin = $this->signIn('admin');
        $this->client->request('GET', '/admin');
        $locked = $this->content('post', 'Locked')->published()->create();
        $own = $this->changes($locked)->by($admin)->title('Unlocked')->create();
        $theirs = $this->changes($locked)->by($this->createUser('Eda', ['editor']))->title('Freed')->submitted()->create();
        $open = $this->content('post', 'Open')->published()->create();
        $actions = $this->service(Actions::class);

        $refused = [
            ['content.update', ['content' => $locked->id, 'title' => 'Mine now']],
            ['content.transition', ['content' => $locked->id, 'transition' => 'unpublish']],
            ['content.trash', ['content' => $locked->id]],
            ['content.purge', ['content' => $locked->id]],
            ['content.restore', ['content' => $locked->id]],
            ['content.publish_changes', ['content' => $locked->id, 'pending_revision' => $theirs->id]],
            ['content.schedule_changes', ['content' => $locked->id, 'pending_revision' => $theirs->id, 'at' => '2030-01-01T09:00:00+00:00']],
            ['content.review_changes', ['content' => $locked->id, 'pending_revision' => $theirs->id, 'verdict' => 'approve']],
            ['content.submit_changes', ['content' => $locked->id, 'pending_revision' => $own->id]],
        ];

        foreach ($refused as [$action, $arguments]) {
            self::assertNotNull($actions->denied($action, $arguments), $action.' is refused on a locked item.');
        }

        // An item the rule doesn't lock, the same actions: theirs to take
        self::assertNull($actions->denied('content.update', ['content' => $open->id, 'title' => 'Still open']));
        self::assertNull($actions->denied('content.transition', ['content' => $open->id, 'transition' => 'unpublish']));
    }

    public function testRestoringAScheduledItemTakesTheRightToPublish(): void
    {
        // M-02: someone who may edit but not publish changes what visitors will see, through the history
        $this->service(SiteRoles::class)->create('writer', 'Writer');
        $this->service(Permissions::class)->grant('writer', ['quire.admin.access', 'content.post.view_drafts', 'content.post.edit_any']);
        $writer = $this->service(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', 'Opening hours'));
        $post = $writer->update(new UpdateContent($post->id, $post->version)->title('Opening hours, checked'));
        $post = $writer->transition($post->id, $post->version, Status::SCHEDULE, new \DateTimeImmutable('+1 day'));
        $oldest = array_last($this->service(RevisionRepository::class)->forContent($post->id));
        self::assertNotNull($oldest);

        $this->signIn($this->createUser('Wren', ['writer']));
        $this->client->request('POST', '/admin/content/post/'.$post->id.'/restore', ['_token' => $this->csrfToken('quire_admin_content_editor'), 'revision' => $oldest->id, 'version' => $post->version]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Opening hours, checked', $this->service(ContentRepository::class)->get($post->id)->title);

        // Who may publish restores it
        $this->signIn('editor');
        $this->client->request('POST', '/admin/content/post/'.$post->id.'/restore', ['_token' => $this->csrfToken('quire_admin_content_editor'), 'revision' => $oldest->id, 'version' => $post->version]);
        self::assertResponseRedirects('/admin/content/post/'.$post->id);
        self::assertSame('Opening hours', $this->service(ContentRepository::class)->get($post->id)->title);
    }

    /**
     * Back as it was for the next action: published, out of the trash.
     */
    private function restore(Content $item): Content
    {
        $repository = $this->service(ContentRepository::class);
        $writer = $this->service(ContentWriter::class);
        $item = $repository->find($item->id) ?? throw new \LogicException('Gone.');

        if ($item->isTrashed()) {
            $writer->restore($item->id);
            $item = $repository->get($item->id);
        }

        return Status::PUBLISHED === $item->status ? $item : $writer->transition($item->id, $item->version, Status::PUBLISH);
    }
}
