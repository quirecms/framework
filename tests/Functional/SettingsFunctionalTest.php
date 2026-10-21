<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SettingsFunctionalTest extends WebTestCase
{
    protected function tearDown(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM setting');
        parent::tearDown();
    }

    public function testPagesUseTheCurrentSettings(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertPageTitleSame('Quire');

        self::getContainer()->get(Settings::class)->set('site.title', 'My site');
        self::getContainer()->get(Settings::class)->set('quire-fixture-hello.greeting', 'Howdy');

        $client->request('GET', '/');
        self::assertPageTitleSame('My site');

        $client->request('GET', '/_fixture/hello/Ada');
        self::assertSelectorTextSame('h1', 'Howdy, Ada!');
    }

    public function testListCommand(): void
    {
        self::getContainer()->get(Settings::class)->set('site.title', 'My site');

        $tester = $this->command('quire:settings:list');
        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();

        self::assertMatchesRegularExpression('/site\.title\s+My site\s+database\s+string\s+core/', $display);
        self::assertMatchesRegularExpression('/site\.description\s+default\s+string\s+core/', $display);
        self::assertMatchesRegularExpression('/quire-fixture-hello\.shout\s+false\s+default\s+bool\s+quire-fixture-hello/', $display);

        $tester->execute(['prefix' => 'site.']);
        self::assertStringNotContainsString('quire-fixture-hello', $tester->getDisplay());
    }

    public function testGetCommandPrintsTheRawValue(): void
    {
        $tester = $this->command('quire:settings:get');

        self::assertSame(0, $tester->execute(['key' => 'site.title']));
        self::assertSame("Quire\n", $tester->getDisplay());

        self::assertSame(0, $tester->execute(['key' => 'quire-fixture-hello.shout']));
        self::assertSame("false\n", $tester->getDisplay());
    }

    public function testSetCommandParsesValuesByType(): void
    {
        $tester = $this->command('quire:settings:set');

        self::assertSame(0, $tester->execute(['key' => 'quire-fixture-hello.shout', 'value' => 'yes']));
        self::assertStringContainsString('quire-fixture-hello.shout is now true.', $tester->getDisplay());
        self::assertTrue(self::getContainer()->get(Settings::class)->getBool('quire-fixture-hello.shout'));

        self::assertSame(0, $tester->execute(['key' => 'quire-fixture-hello.shout', '--default' => true]));
        self::assertFalse(self::getContainer()->get(Settings::class)->getBool('quire-fixture-hello.shout'));
    }

    public function testSetCommandReportsErrors(): void
    {
        $tester = $this->command('quire:settings:set');

        self::assertSame(1, $tester->execute(['key' => 'quire-fixture-hello.shout', 'value' => 'maybe']));
        self::assertStringContainsString('"maybe" is not a valid bool value', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['key' => 'site.titel', 'value' => 'x']));
        self::assertStringContainsString('Did you mean "site.title"?', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');

        self::assertSame(1, $tester->execute(['key' => 'site.title']));
        self::assertStringContainsString('Pass either a value or --default.', $tester->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find($name));
    }
}
