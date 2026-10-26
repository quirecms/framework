<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Testing\AdminTestCase;
use Quire\User\User;
use Symfony\Component\DomCrawler\Crawler;

final class EditorTest extends AdminTestCase
{
    public function testCreatingAnItemWithTheForm(): void
    {
        $editor = $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin/content/post');
        $crawler = $this->client->click($crawler->filter('a.q-btn')->link());

        self::assertSelectorTextSame('h1', 'New item');
        $this->client->submit($crawler->filter('button[name=intent][value="save"]')->form(['title' => 'From the form', 'body' => 'Some *Markdown*.', 'excerpt' => 'In short.']));

        $item = $this->latest();
        self::assertNotNull($item);
        self::assertResponseRedirects('/admin/content/post/'.$item->id, 303);
        self::assertSame(['From the form', 'from-the-form', 'Some *Markdown*.', 'In short.', Status::DRAFT, $editor->id], [$item->title, $item->slug, $item->body, $item->excerpt, $item->status, $item->authorId]);

        $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--success', 'Created.');
        self::assertSelectorTextSame('h1', 'From the form');
        self::assertInputValueSame('slug', 'from-the-form');
    }

    public function testMistakesAreShownNextToTheirFieldsAndInASummary(): void
    {
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin/content/post/new');

        $crawler = $this->client->submit($crawler->filter('button[name=intent][value="save"]')->form(['title' => '', 'body' => 'Kept.', 'slug' => 'Not A Slug!']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['#title', '#slug'], $crawler->filter('.q-error-summary a')->each(static fn (Crawler $a): string => (string) $a->attr('href')));
        self::assertSame('true', $crawler->filter('#title')->attr('aria-invalid'));
        self::assertSame('title-error', $crawler->filter('#title')->attr('aria-describedby'));
        self::assertSelectorExists('#title-error');
        self::assertSame('Kept.', $crawler->filter('#body')->text(), 'What was entered stays.');
        self::assertNull($this->latest(), 'Nothing was saved.');
    }

    public function testPreviewWithoutSaving(): void
    {
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin/content/post/new');
        self::assertSelectorExists('section.preview[hidden]');

        $crawler = $this->client->submit($crawler->filter('button[name=intent][value="preview"]')->form(['title' => 'Draft', 'body' => 'Some **bold** text.']));

        self::assertResponseIsSuccessful();
        self::assertSame('<p>Some <strong>bold</strong> text.</p>', trim($crawler->filter('.preview__body')->html()));
        self::assertSelectorNotExists('section.preview[hidden]');
        self::assertNull($this->latest());

        // The live preview, as the editor's script asks for it.
        $token = (string) $crawler->filter('main input[name=_token]')->attr('value');
        $this->client->request('POST', '/admin/content/post/preview', ['_token' => $token, 'body' => '# Title']);
        self::assertSame('<h1 id="preview-title">Title</h1>', trim((string) $this->client->getResponse()->getContent()), 'Its IDs aren\'t the form\'s, e.g. "title".');

        $this->client->request('POST', '/admin/content/post/preview', ['_token' => 'forged', 'body' => '# Title']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSavingAndTakingATransition(): void
    {
        $this->signIn('editor');
        $item = $this->post('Draft');
        $crawler = $this->client->request('GET', '/admin/content/post/'.$item->id);

        self::assertSame(['save', 'preview', 'transition:publish', 'transition:schedule'], $crawler->filter('.editor button[name=intent]')->each(static fn (Crawler $b): string => (string) $b->attr('value')));
        $this->client->submit($crawler->filter('button[name=intent][value="transition:publish"]')->form(['title' => 'Published now']));
        $this->client->followRedirect();

        $item = $this->service(ContentRepository::class)->get($item->id);
        self::assertSame(['Published now', Status::PUBLISHED], [$item->title, $item->status]);
        self::assertSelectorTextSame('.q-notice--success', 'Saved, and the status is now: Published.');
    }

    public function testSchedulingInTheSitesTimeZone(): void
    {
        $this->signIn('editor');
        $this->setSetting('site.timezone', 'Europe/Berlin');
        $item = $this->post('Later');
        $crawler = $this->client->request('GET', '/admin/content/post/'.$item->id);
        self::assertSelectorTextContains('label[for=publish_at]', 'Europe/Berlin');

        $this->client->submit($crawler->filter('button[name=intent][value="transition:schedule"]')->form(['publish_at' => '']));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.q-notice--error', 'Choose when to publish it.');
        self::assertSame(Status::DRAFT, $this->service(ContentRepository::class)->get($item->id)->status);

        $this->client->submit($crawler->filter('button[name=intent][value="transition:schedule"]')->form(['publish_at' => '2030-01-02T09:30']));

        $item = $this->service(ContentRepository::class)->get($item->id);
        self::assertSame(Status::SCHEDULED, $item->status);
        self::assertSame('2030-01-02T08:30:00+00:00', $item->publishedAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM), 'In Berlin, 9:30 is 8:30 UTC in winter.');
    }

    public function testSomeoneElsesChangesAreNeverOverwrittenUnknowingly(): void
    {
        $this->signIn('editor');
        $item = $this->post('Original');
        $crawler = $this->client->request('GET', '/admin/content/post/'.$item->id);
        $form = $crawler->filter('button[name=intent][value="save"]')->form(['title' => 'Mine']);

        // Someone else saves first.
        $this->service(ContentWriter::class)->update(new UpdateContent($item->id, $item->version)->title('Theirs'));

        $crawler = $this->client->submit($form);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('.q-notice--warning', 'Someone saved this item after you opened it.');
        self::assertInputValueSame('title', 'Mine', 'What was entered stays.');
        self::assertSame('Theirs', $this->service(ContentRepository::class)->get($item->id)->title);

        // Saving again replaces their changes, knowingly.
        $this->client->submit($crawler->filter('button[name=intent][value="save"]')->form());
        self::assertResponseRedirects();
        self::assertSame('Mine', $this->service(ContentRepository::class)->get($item->id)->title);
    }

    public function testWhoMayEditWhat(): void
    {
        $member = $this->signIn('member');
        $theirs = $this->post('Theirs', author: $member);
        $others = $this->post('Others');
        $this->service(Permissions::class)->grant('member', ['quire.admin.access', 'content.post.edit_own']);

        $this->client->request('GET', '/admin/content/post/'.$others->id);
        self::assertResponseStatusCodeSame(404, 'Others\' items aren\'t in their reach.');
        $this->client->request('GET', '/admin/content/post/new');
        self::assertResponseStatusCodeSame(403, 'Creating takes "create".');

        $crawler = $this->client->request('GET', '/admin/content/post/'.$theirs->id);
        self::assertResponseIsSuccessful();
        self::assertSame(['save', 'preview'], $crawler->filter('.editor button[name=intent]')->each(static fn (Crawler $b): string => (string) $b->attr('value')), 'Publishing takes "publish".');
        self::assertCount(0, $crawler->filter('#author_id'), 'Only those who may edit anyone\'s choose the author.');

        $this->client->submit($crawler->filter('button[name=intent][value="save"]')->form(['title' => 'Renamed']));
        self::assertSame(['Renamed', $member->id], [$this->service(ContentRepository::class)->get($theirs->id)->title, $this->service(ContentRepository::class)->get($theirs->id)->authorId], 'They stay the author.');

        // Once published, they write changes, which someone who may publish publishes.
        $published = $this->service(ContentWriter::class)->transition($theirs->id, $this->service(ContentRepository::class)->get($theirs->id)->version, Status::PUBLISH);
        $crawler = $this->client->request('GET', '/admin/content/post/'.$published->id);
        self::assertSame(['save', 'preview', 'submit'], $crawler->filter('.editor button[name=intent]')->each(static fn (Crawler $b): string => (string) $b->attr('value')));
        self::assertSelectorNotExists('fieldset.editor__main[disabled]');
    }

    public function testPagesChooseTheirParentButNotThemselvesOrTheirChildren(): void
    {
        $this->signIn('editor');
        $writer = $this->service(ContentWriter::class);
        $about = $writer->create(new CreateContent('page', 'About'));
        $team = $writer->create(new CreateContent('page', 'Team', parentId: $about->id));
        $writer->create(new CreateContent('page', 'Contact'));

        $crawler = $this->client->request('GET', '/admin/content/page/'.$about->id);
        self::assertSame(['(top level)', 'Contact'], $crawler->filter('#parent_id option')->each(static fn (Crawler $o): string => $o->text()));

        $crawler = $this->client->request('GET', '/admin/content/page/'.$team->id);
        self::assertSame($about->id, $crawler->filter('#parent_id option[selected]')->attr('value'));
    }

    private function post(string $title, ?User $author = null): Content
    {
        return $this->service(ContentWriter::class)->create(new CreateContent('post', $title, authorId: $author?->id));
    }

    private function latest(): ?Content
    {
        return $this->service(ContentRepository::class)->query('post')->withoutVisibilityScope()->orderBy('created_at', 'desc')->first();
    }
}
