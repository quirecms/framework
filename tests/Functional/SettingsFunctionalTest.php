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
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM quire.setting');
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

    public function testExportAndImport(): void
    {
        $settings = self::getContainer()->get(Settings::class);
        $settings->set('site.title', 'Tournament "News"');
        $settings->set('quire.content.check_levels', ['core.alt-text' => 'error']);

        $export = $this->command('quire:settings:export');
        self::assertSame(0, $export->execute([]));
        $exported = $this->json($export);
        self::assertSame(['core.alt-text' => 'error'], $exported['quire.content.check_levels'] ?? null);
        self::assertSame('Tournament "News"', $exported['site.title'] ?? null);
        self::assertArrayNotHasKey('site.locale', $exported, 'Defaults only with --all.');
        $export->execute(['prefix' => 'site.', '--all' => true]);
        self::assertSame(['site.description', 'site.homepage', 'site.homepage_listing', 'site.homepage_page', 'site.homepage_route', 'site.locale', 'site.share_image', 'site.timezone', 'site.title'], array_keys($this->json($export)));

        $file = tempnam(sys_get_temp_dir(), 'settings');
        $import = $this->command('quire:settings:import');

        // Checked first: nothing changes when a value doesn't fit.
        file_put_contents($file, json_encode(['site.title' => 'Results', 'quire.content.per_page' => 'many', 'acme.colour' => 'red'], \JSON_THROW_ON_ERROR));
        self::assertSame(1, $import->execute(['file' => $file]));
        self::assertStringContainsString('acme.colour: no enabled extension declares it.', $this->flat($import));
        self::assertStringContainsString('quire.content.per_page: ', $this->flat($import));
        self::assertSame('Tournament "News"', $this->setting('site.title'));

        file_put_contents($file, json_encode(['site.title' => 'Results', 'quire.content.per_page' => 25, 'acme.colour' => 'red', 'site.locale' => 'en'], \JSON_THROW_ON_ERROR));
        self::assertSame(0, $import->execute(['file' => $file, '--skip-unknown' => true, '--dry-run' => true]));
        self::assertStringContainsString('Nothing was changed (--dry-run).', $this->flat($import));
        self::assertSame('Tournament "News"', $this->setting('site.title'));

        self::assertSame(0, $import->execute(['file' => $file, '--skip-unknown' => true]));
        self::assertStringContainsString('Left out, as no enabled extension declares them: acme.colour.', $this->flat($import));
        self::assertStringContainsString('Changed 2 settings.', $this->flat($import), 'The language already was "en".');

        // As YAML too, both ways.
        $export->execute(['prefix' => 'site.', '--format' => 'yaml']);
        self::assertSame("site.title: Results\n", $export->getDisplay(), 'Only what the site changed.');
        file_put_contents($file, "site.description: 'Draws and results'\nquire.content.check_levels:\n  core.alt-text: warning\n");
        self::assertSame(0, $import->execute(['file' => $file]));
        self::assertSame('Draws and results', $this->setting('site.description'));
        self::assertSame(['core.alt-text' => 'warning'], $this->setting('quire.content.check_levels'));
        self::assertSame(['Results', 25], [$this->setting('site.title'), $this->setting('quire.content.per_page')]);
        unlink($file);
    }

    /**
     * @return array<mixed>
     */
    private function json(CommandTester $tester): array
    {
        $json = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /** As the commands, which run in a kernel of their own, left it. */
    private function setting(string $key): mixed
    {
        $settings = self::getContainer()->get(Settings::class);
        $settings->reset();

        return $settings->get($key);
    }

    private function flat(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find($name));
    }
}
