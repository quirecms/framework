<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * Slots and the template API on Quire's built-in templates, filled by the fixture extension.
 */
final class SlotsTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testExtensionsFillTheSlotsOfTheBuiltInTemplates(): void
    {
        $this->publish('Hello world');

        $this->client->request('GET', '/blog/hello-world');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Like "Hello world"', 'Thanks for reading "Hello world"'],
            $this->client->getCrawler()->filter('article .fixture-likes, article .fixture-after-body')->each(static fn ($p): string => $p->text()),
            'Fragments come by priority, highest first.',
        );
        self::assertSelectorTextSame('footer .fixture-footer', 'Hello from Quire');
    }

    public function testInDebugModeEachFragmentSaysWhereItComesFrom(): void
    {
        $this->publish('Hello world');

        $this->client->request('GET', '/blog/hello-world');

        self::assertStringContainsString("<!-- slot content.after_body: quire-fixture-hello -->\n<p class=\"fixture-likes\">", (string) $this->client->getResponse()->getContent());
    }

    public function testTheSiteVariableIsCurrentInLongRunningWorkers(): void
    {
        $this->publish('Hello world');
        $this->client->request('GET', '/blog/hello-world');
        self::assertSelectorTextSame('header a', 'Quire');

        self::getContainer()->get(Settings::class)->set('site.title', 'My blog');
        $this->client->request('GET', '/blog/hello-world');

        self::assertSelectorTextSame('header a', 'My blog');
        self::assertSelectorTextSame('footer .fixture-footer', 'Hello from My blog');
    }

    public function testTemplatesReadOnlySettingsExposedToThem(): void
    {
        $twig = self::getContainer()->get(Environment::class);

        self::assertSame('Hello', $twig->createTemplate("{{ quire_setting('quire-fixture-hello.greeting') }}")->render());

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageIsOrContains('The setting "quire-fixture-hello.shout" isn\'t available to templates.');
        $twig->createTemplate("{{ quire_setting('quire-fixture-hello.shout') }}")->render();
    }

    public function testTemplatesCanAskWhetherAnExtensionRuns(): void
    {
        $twig = self::getContainer()->get(Environment::class);

        self::assertSame('yes no', $twig->createTemplate("{{ quire_extension_enabled('quire-fixture-hello') ? 'yes' : 'no' }} {{ quire_extension_enabled('acme-missing') ? 'yes' : 'no' }}")->render());
        self::assertSame('filled empty', $twig->createTemplate("{{ quire_slot_filled('footer') ? 'filled' : 'empty' }} {{ quire_slot_filled('sidebar') ? 'filled' : 'empty' }}")->render());
    }

    private function publish(string $title): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $item = $writer->create(new CreateContent('post', $title));
        $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));
    }
}
