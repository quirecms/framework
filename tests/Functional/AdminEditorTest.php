<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Field\FieldIndexes;
use Quire\Content\Status;
use Quire\Settings\Settings;
use Quire\User\CreateUser;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The editor with the fields of the fixture extension (see tests/Fixtures/extensions/hello/extend.php):
 * a widget for each of core's field types, the extension's own widget for its colour type, and relations.
 */
final class AdminEditorTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    public static function setUpBeforeClass(): void
    {
        // Indexes are built concurrently, which can't happen inside the test's transaction.
        self::getContainer()->get(FieldIndexes::class)->sync();
        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        self::getContainer()->get(Settings::class)->set('site.timezone', 'Europe/Berlin');

        self::getContainer()->get(UserWriter::class)->create(new CreateUser('Editor', 'editor@example.com', password: 'correct-horse-42', roles: ['editor']));
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'editor', '_password' => 'correct-horse-42']);
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testEachFieldHasTheControlItsWidgetNames(): void
    {
        $crawler = $this->client->request('GET', '/admin/content/post/new');

        self::assertSame(['', 'happy', 'sad'], $crawler->filter('select#field-mood option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame(['number', '1', '1', '5'], $this->attributes($crawler, '#field-rating', 'type', 'step', 'min', 'max'));
        self::assertSame(['number', 'any', '0'], $this->attributes($crawler, '#field-score', 'type', 'step', 'min'));
        self::assertSame(['checkbox'], $this->attributes($crawler, '#field-featured', 'type'));
        self::assertSame(['text', '100'], $this->attributes($crawler, '#field-subtitle', 'type', 'maxlength'));
        self::assertSame(['text'], $this->attributes($crawler, '#field-tags', 'type'));
        self::assertSame(['date'], $this->attributes($crawler, '#field-due', 'type'));
        self::assertSame(['datetime-local'], $this->attributes($crawler, '#field-event_at', 'type'));
        self::assertCount(1, $crawler->filter('textarea#field-extra.q-code'));
        self::assertSame(['color'], $this->attributes($crawler, '#field-accent', 'type'), 'The extension\'s widget for its field type.');
        self::assertSame('Rating', $crawler->filter('label[for=field-rating]')->text());
    }

    public function testValuesGoInAndComeBackOut(): void
    {
        $crawler = $this->client->request('GET', '/admin/content/post/new');
        $this->client->submit($crawler->filter('button[name=intent][value="save"]')->form([
            'title' => 'All the fields',
            'fields[mood]' => 'happy',
            'fields[rating]' => '4',
            'fields[score]' => '2.5',
            'fields[featured]' => '1',
            'fields[subtitle]' => 'Below the title',
            'fields[tags]' => 'news, php, news',
            'fields[due]' => '2026-12-24',
            'fields[event_at]' => '2026-12-24T18:00',
            'fields[extra]' => '{"seats": 12}',
            'fields[accent]' => '#ff8800',
        ]));

        $item = $this->latest();
        $fields = array_intersect_key($item->fields, array_flip(['mood', 'rating', 'score', 'featured', 'subtitle', 'tags', 'due', 'event_at', 'extra', 'accent']));
        ksort($fields);
        self::assertSame([
            'accent' => '#ff8800', 'due' => '2026-12-24', 'event_at' => '2026-12-24T17:00:00+00:00', 'extra' => ['seats' => 12], 'featured' => true,
            'mood' => 'happy', 'rating' => 4, 'score' => 2.5, 'subtitle' => 'Below the title', 'tags' => ['news', 'php'],
        ], $fields, 'Dates and times are entered in the site\'s time zone.');

        $crawler = $this->client->request('GET', '/admin/content/post/'.$item->id);
        self::assertSame('happy', $crawler->filter('#field-mood option[selected]')->attr('value'));
        self::assertInputValueSame('fields[tags]', 'news, php');
        self::assertInputValueSame('fields[event_at]', '2026-12-24T18:00');
        self::assertNotNull($crawler->filter('#field-featured')->attr('checked'));
        self::assertStringContainsString('"seats": 12', $crawler->filter('#field-extra')->text());

        // Unticking a box and emptying a field.
        $form = $crawler->filter('button[name=intent][value="save"]')->form();
        $featured = $form['fields[featured]'];
        self::assertInstanceOf(ChoiceFormField::class, $featured);
        $featured->untick();
        $form['fields[subtitle]'] = '';
        $this->client->submit($form);

        $item = self::getContainer()->get(ContentRepository::class)->get($item->id);
        self::assertFalse($item->fields['featured']);
        self::assertArrayNotHasKey('subtitle', $item->fields);
    }

    public function testWhatCantBeReadOrBreaksARuleIsShownAtItsField(): void
    {
        $crawler = $this->client->request('GET', '/admin/content/post/new');
        $crawler = $this->client->submit($crawler->filter('button[name=intent][value="save"]')->form([
            'title' => 'Mistakes',
            'fields[rating]' => '2.5',
            'fields[score]' => '-1',
            'fields[extra]' => '{"seats": ',
            'fields[accent]' => '#000000',
        ]));

        self::assertResponseStatusCodeSame(422);
        $errors = array_combine(
            $crawler->filter('.q-error-summary a')->each(static fn (Crawler $a): string => (string) $a->attr('href')),
            $crawler->filter('.q-error-summary a')->each(static fn (Crawler $a): string => $a->text()),
        );
        self::assertSame(['#field-rating', '#field-extra'], array_keys($errors), 'What can\'t be read is reported before anything is saved.');
        self::assertSame('Enter a whole number.', $errors['#field-rating']);
        self::assertStringStartsWith('This isn\'t valid JSON:', $errors['#field-extra']);
        self::assertSame('field-extra-error', $crawler->filter('#field-extra')->attr('aria-describedby'));
        self::assertInputValueSame('fields[score]', '-1', 'What was entered stays.');

        $crawler = $this->client->submit($crawler->filter('button[name=intent][value="save"]')->form(['fields[rating]' => '9', 'fields[extra]' => '{}']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['#field-rating', '#field-score', '#field-accent'], $crawler->filter('.q-error-summary a')->each(static fn (Crawler $a): string => (string) $a->attr('href')), 'Then the fields\' rules, e.g. the largest rating and the palette.');
    }

    public function testRelationsOfferWhatTheUserMaySeeAndSearchFindsMore(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $markdown = $writer->create(new CreateContent('post', 'Markdown tips'));
        $writer->transition($markdown->id, $markdown->version, Status::PUBLISH);
        $draft = $writer->create(new CreateContent('post', 'Draft post'));

        $crawler = $this->client->request('GET', '/admin/content/greeting_card/new');
        self::assertNotNull($crawler->filter('select#field-posts')->attr('multiple'));
        self::assertSame('fields[posts][]', $crawler->filter('select#field-posts')->attr('name'));
        self::assertSame(['Draft post (Post, Draft)', 'Markdown tips (Post)'], $crawler->filter('#field-posts option')->each(static fn (Crawler $o): string => $o->text()), 'Editors see drafts too.');
        self::assertNull($crawler->filter('select#field-reply_to')->attr('multiple'));

        $this->client->submit($crawler->filter('button[name=intent][value="save"]')->form(['title' => 'For Ada', 'fields[recipient]' => 'Ada', 'fields[posts]' => [$markdown->id, $draft->id]]));
        $card = self::getContainer()->get(ContentRepository::class)->query('greeting_card')->withoutVisibilityScope()->first();
        self::assertNotNull($card);
        self::assertEqualsCanonicalizing([$markdown->id, $draft->id], $card->fields['posts'] ?? null);

        $this->client->request('GET', '/admin/content-search?types=post&q=mark');
        self::assertSame([['id' => $markdown->id, 'label' => 'Markdown tips (Post)']], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    /**
     * @return list<string|null>
     */
    private function attributes(Crawler $crawler, string $selector, string ...$names): array
    {
        $element = $crawler->filter($selector);
        self::assertCount(1, $element, $selector);

        return array_values(array_map($element->attr(...), $names));
    }

    private function latest(): Content
    {
        $item = self::getContainer()->get(ContentRepository::class)->query('post')->withoutVisibilityScope()->orderBy('created_at', 'desc')->first();
        self::assertNotNull($item);

        return $item;
    }
}
