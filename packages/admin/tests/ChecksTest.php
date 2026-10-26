<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Content\Check\ContentChecker;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Content\Status;
use Quire\Testing\AdminTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The content checks in the admin: shown while writing, and errors block publishing.
 */
final class ChecksTest extends AdminTestCase
{
    public function testTheEditorShowsWhatTheChecksFind(): void
    {
        $this->signIn('editor');
        $post = $this->service(ContentWriter::class)->create(new CreateContent('post', 'Pictures', body: "Intro.\n\n![](/chart.png)\n", excerpt: 'Short.'));

        $crawler = $this->client->request('GET', '/admin/content/post/'.$post->id);

        self::assertSelectorTextSame('.checks__summary', 'No errors, 1 warning.');
        self::assertSame(['Warning: The image “chart.png” has no description. Describe it in the brackets: ![what it shows](/chart.png). Line 3 (Images have a description)'], $this->findings($crawler));
        self::assertSame('#body', $crawler->filter('.checks a')->attr('href'));

        // As the editor types, with what's in the form.
        $token = (string) $crawler->filter('input[name=_token]')->attr('value');
        $crawler = $this->client->request('POST', '/admin/content/post/check', ['_token' => $token, 'id' => $post->id, 'title' => 'Pictures', 'body' => '![A chart](/chart.png)', 'excerpt' => '']);
        self::assertSame('No errors, 1 warning.', $crawler->filter('.checks__summary')->text());
        self::assertStringContainsString('There\'s no excerpt', $crawler->filter('.checks')->text());
    }

    public function testErrorsBlockPublishing(): void
    {
        $this->setSetting(ContentChecker::LEVELS_SETTING, ['core.alt-text' => 'error']);
        $this->signIn('editor');
        $crawler = $this->client->request('GET', '/admin/content/post/new');

        $this->client->submit($crawler->filter('button[name=intent][value="transition:publish"]')->form(['title' => 'Pictures', 'body' => '![](/chart.png)', 'excerpt' => 'Short.']));
        $this->client->followRedirect();

        self::assertSelectorTextSame('.notice--success', 'Created.');
        self::assertSelectorTextSame('.notice--error', 'The image “chart.png” has no description. Describe it in the brackets: ![what it shows](/chart.png).');
        self::assertSelectorTextContains('.checks__summary', '1 error');
        self::assertSelectorTextContains('.checks__summary', 'Errors block publishing.');

        $item = $this->service(ContentRepository::class)->query('post')->withoutVisibilityScope()->first();
        self::assertSame(Status::DRAFT, $item?->status, 'Saved as a draft, not published.');
    }

    public function testReviewersSeeTheChecksOfTheChanges(): void
    {
        $this->signIn('editor');
        $writer = $this->service(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', 'Pictures', body: 'Text.', excerpt: 'Short.'));
        $post = $writer->transition($post->id, $post->version, Status::PUBLISH);
        $pendingWriter = $this->service(PendingRevisionWriter::class);
        $pending = $pendingWriter->create($post->id);
        $pendingWriter->update(new UpdatePendingRevision($pending->id, $pending->version)->body('![](/chart.png)'));

        $crawler = $this->client->request('GET', '/admin/content/post/'.$post->id.'/changes/'.$pending->id);

        self::assertSame('No errors, 1 warning.', $crawler->filter('.checks__summary')->text());
    }

    /**
     * @return list<string>
     */
    private function findings(Crawler $crawler): array
    {
        return $crawler->filter('.checks li')->each(static fn (Crawler $li): string => trim((string) preg_replace('/\s+/', ' ', $li->text())));
    }
}
