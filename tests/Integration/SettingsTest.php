<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Settings\Event\SettingChanged;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Exception\UnknownSettingException;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class SettingsTest extends KernelTestCase
{
    private Connection $connection;

    /** @var list<SettingChanged> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM quire.setting');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM quire.setting');
        parent::tearDown();
    }

    public function testDefaultsComeFromTheDeclarations(): void
    {
        $settings = $this->settings();

        self::assertSame('Quire', $settings->get('site.title'));
        self::assertSame('Hello', $settings->getString('quire-fixture-hello.greeting'));
        self::assertSame('default', $settings->source('site.title'));
    }

    public function testSetStoresTheValueAndDispatchesAnEvent(): void
    {
        $settings = $this->settings();
        $settings->set('site.title', 'My site');

        self::assertSame('My site', $settings->get('site.title'));
        self::assertSame('database', $settings->source('site.title'));
        self::assertSame('My site', $this->settings()->get('site.title'), 'Persisted for other requests.');
        self::assertCount(1, $this->events);
        self::assertSame(['site.title', 'Quire', 'My site'], [$this->events[0]->key, $this->events[0]->oldValue, $this->events[0]->newValue]);
    }

    public function testSettingTheSameValueDispatchesNothing(): void
    {
        $this->settings()->set('site.title', 'Quire');

        self::assertSame([], $this->events);
    }

    public function testRestoreDefault(): void
    {
        $settings = $this->settings();
        $settings->set('site.title', 'My site');
        $settings->restoreDefault('site.title');

        self::assertSame('Quire', $settings->get('site.title'));
        self::assertSame('default', $settings->source('site.title'));
        self::assertEquals(0, $this->connection->fetchOne('SELECT COUNT(*) FROM quire.setting'));
        self::assertSame('Quire', $this->events[1]->newValue);
    }

    public function testRejectsValuesOfTheWrongType(): void
    {
        $this->expectException(InvalidSettingValueException::class);
        $this->expectExceptionMessageIsOrContains('Invalid value for setting "site.title": expected a string, got int.');

        $this->settings()->set('site.title', 42);
    }

    public function testUnknownKeysSuggestCloseMatches(): void
    {
        $this->expectException(UnknownSettingException::class);
        $this->expectExceptionMessageIsOrContains('No setting "site.titel" is declared. Declare it with Extend\Settings::define() in extend.php. Did you mean "site.title"?');

        $this->settings()->get('site.titel');
    }

    public function testStoredValuesThatNoLongerFitFallBackToTheDefault(): void
    {
        // E.g. stored before the setting's type changed in a new version of the extension.
        $this->connection->executeStatement("INSERT INTO quire.setting (name, value, updated_at) VALUES ('site.title', '123', now())");

        self::assertSame('Quire', $this->settings()->get('site.title'));
        self::assertSame('default', $this->settings()->source('site.title'));
    }

    public function testEnvironmentVariablesOverrideAndLockSettings(): void
    {
        $settings = $this->settings(['QUIRE_SETTING__SITE__TITLE' => 'From the environment']);
        $this->connection->executeStatement("INSERT INTO quire.setting (name, value, updated_at) VALUES ('site.title', '\"Stored\"', now())");

        self::assertSame('From the environment', $settings->get('site.title'));
        self::assertSame('environment', $settings->source('site.title'));

        $this->expectException(InvalidSettingValueException::class);
        $this->expectExceptionMessageIsOrContains('The setting "site.title" is set by the environment variable QUIRE_SETTING__SITE__TITLE');

        $settings->set('site.title', 'Changed');
    }

    public function testEnvironmentVariablesAreParsedByType(): void
    {
        self::assertTrue($this->settings(['QUIRE_SETTING__QUIRE_FIXTURE_HELLO__SHOUT' => 'yes'])->getBool('quire-fixture-hello.shout'));
    }

    public function testInvalidEnvironmentVariablesAreReported(): void
    {
        $this->expectException(InvalidSettingValueException::class);
        $this->expectExceptionMessageIsOrContains('The environment variable QUIRE_SETTING__QUIRE_FIXTURE_HELLO__SHOUT, which overrides setting "quire-fixture-hello.shout", is invalid: "maybe" is not a valid bool value');

        $this->settings(['QUIRE_SETTING__QUIRE_FIXTURE_HELLO__SHOUT' => 'maybe'])->get('quire-fixture-hello.shout');
    }

    public function testAllReturnsCurrentValues(): void
    {
        $settings = $this->settings();
        $settings->set('site.description', 'A test site');

        self::assertSame(['site.description' => 'A test site', 'site.locale' => 'en', 'site.title' => 'Quire'], array_intersect_key($settings->all('site.'), array_flip(['site.description', 'site.locale', 'site.title'])));
        self::assertSame(['quire-fixture-hello.greeting'], array_keys(array_filter($settings->all('quire-fixture-hello.'), static fn (mixed $value): bool => 'Hello' === $value)), 'Only keys with the prefix.');
    }

    public function testTypedGettersRefuseOtherTypes(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The setting "site.title" is a string setting, so it has no int value.');

        $this->settings()->getInt('site.title');
    }

    /**
     * @param array<string, string> $environment
     */
    private function settings(array $environment = []): Settings
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SettingChanged::class, function (SettingChanged $event): void {
            $this->events[] = $event;
        });

        return new Settings(self::getContainer()->get(SettingsSchema::class), $this->connection, $dispatcher, $environment);
    }
}
