<?php

declare(strict_types=1);

namespace Quire\ThemeDefault\Tests;

use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Permission\Permissions;
use Quire\Testing\ExtensionTestCase;
use Quire\User\CreateUser;
use Quire\User\User;
use Quire\User\UserWriter;

/**
 * An unpublished item as those who may preview it see it in the default theme, with the admin enabled:
 * the notice above the whole site, "Edit in the admin" only for those who may edit it, and its state.
 */
final class PreviewTest extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin'];

    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    public function testAnEditorPreviewsADraftAndCanEditIt(): void
    {
        $draft = $this->service(ContentWriter::class)->create(new CreateContent('post', 'Not yet', body: 'Soon.'));

        $this->client->loginUser($this->user('editor', ['editor']));
        $crawler = $this->client->request('GET', '/blog/not-yet');

        self::assertResponseIsSuccessful();
        self::assertSame('This isn’t published, so only people who may see unpublished content see it.', $crawler->filter('.qd-ribbon--notice p')->text());
        self::assertSame('This isn’t published,', $crawler->filter('.qd-ribbon--notice p b')->text(), 'The lead in bold, as designed.');
        self::assertCount(1, $crawler->filterXPath('//*[contains(@class, "qd-ribbon--notice")]/following::header[contains(@class, "qd-masthead")]'), 'Above the whole site, so it can\'t be taken for content.');
        self::assertSame('/admin/content/post/'.$draft->id, $crawler->filter('.qd-ribbon--notice a')->attr('href'));
        self::assertStringStartsWith('Draft · not scheduled ·', $crawler->filter('main .qd-kicker')->text());
        self::assertSame('All posts', $crawler->filter('.qd-browse a')->text(), 'The way back to the list, as on a published post.');
    }

    public function testWhoMayOnlyPreviewIsNotOfferedToEdit(): void
    {
        $this->service(ContentWriter::class)->create(new CreateContent('post', 'Not yet', body: 'Soon.'));
        $this->service(Permissions::class)->grant('member', ['content.post.view_drafts']);

        $this->client->loginUser($this->user('member', ['member']));
        $crawler = $this->client->request('GET', '/blog/not-yet');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.qd-ribbon--notice'));
        self::assertCount(0, $crawler->filter('.qd-ribbon--notice a'), 'No link to what they may not do.');

        $this->client->request('GET', '/logout');
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/blog/not-yet');
        self::assertResponseStatusCodeSame(404, 'Visitors see nothing at all.');
    }

    public function testAScheduledItemSaysWhen(): void
    {
        $writer = $this->service(ContentWriter::class);
        $item = $writer->create(new CreateContent('post', 'Next week', body: 'Soon.'));
        $writer->transition($item->id, $item->version, Status::SCHEDULE, new \DateTimeImmutable('2030-03-04 10:00'));

        $this->client->loginUser($this->user('editor', ['editor']));
        $crawler = $this->client->request('GET', '/blog/next-week');

        self::assertStringStartsWith('Scheduled for March 4, 2030', $crawler->filter('main .qd-kicker')->text());
    }

    public function testTheOwnersEmptyHomeSaysWhoseItIs(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('.qd-ribbon--notice'), 'A visitor gets the calm empty home.');
        self::assertCount(0, $crawler->filter('ol.qd-steps'));

        $this->client->loginUser($this->user('ada', ['admin']));
        $crawler = $this->client->request('GET', '/');

        self::assertSame('Only you see this', $crawler->filter('.qd-ribbon--notice .qd-tag')->text());
        self::assertSame('You’re signed in as Ada, who may change this site.', $crawler->filter('.qd-ribbon--notice p')->text());
        self::assertSame('/admin', $crawler->filter('.qd-ribbon--notice a')->attr('href'));
        self::assertGreaterThan(0, $crawler->filter('ol.qd-steps li')->count(), 'The steps, ticked from the site\'s state.');
        self::assertSame('Already yours', $crawler->filter('.qd-identity-card h2')->text());
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $name, array $roles): User
    {
        return $this->service(UserWriter::class)->create(new CreateUser(ucfirst($name), $name.'@example.com', roles: $roles));
    }
}
