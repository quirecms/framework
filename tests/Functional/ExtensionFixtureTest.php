<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the fixture extension in tests/Fixtures/extensions/hello through the real kernel.
 * The test environment enables it (see "when@test" in config/packages/quire.yaml).
 */
final class ExtensionFixtureTest extends WebTestCase
{
    public function testRouteServiceTemplateAndGlobal(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_fixture/hello/Ada');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Hello, Ada!');
        self::assertSelectorTextSame('p', 'The fixture extension greets Ada.');
    }

    public function testRouteDefaults(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_fixture/hello');

        self::assertSelectorTextSame('h1', 'Hello, world!');
    }

    public function testAttributeRoutesGetThePrefix(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_fixture/attribute');

        self::assertResponseIsSuccessful();
        self::assertSame('Routed by attribute.', $client->getResponse()->getContent());

        $client->request('GET', '/attribute');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEventListenerRunsOnEveryResponse(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseHeaderSame('X-Quire-Fixture', 'hello');
    }

    public function testConditionalExtendersForMissingExtensionsDontApply(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_fixture/never');

        self::assertResponseStatusCodeSame(404);
    }

    public function testConsoleCommand(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('fixture:hello'));

        self::assertSame(0, $tester->execute([]));
        self::assertSame("The fixture extension greets the console.\n", $tester->getDisplay());
    }
}
