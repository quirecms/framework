<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Status;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * Stylesheets and scripts of extensions and of the site, on Quire's built-in templates.
 */
final class AssetsTest extends WebTestCase
{
    private KernelBrowser $client;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $writer = self::getContainer()->get(ContentWriter::class);
        $item = $writer->create(new CreateContent('post', 'Hello world'));
        $writer->transition($item->id, $item->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testTheHeadLinksTheStylesheetsAndScriptsOfExtensionsAndTheSite(): void
    {
        $crawler = $this->client->request('GET', '/blog/hello-world');

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('#^/assets/quire-fixture-hello/hello-[\w-]+\.css$#', (string) $crawler->filter('head link[rel=stylesheet]')->attr('href'), 'Versioned, so browsers can cache it forever.');

        $importMap = json_decode($crawler->filter('head script[type=importmap]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($importMap);
        $imports = $importMap['imports'] ?? [];
        self::assertIsArray($imports);
        self::assertArrayHasKey('app', $imports, 'The site\'s importmap entrypoint, from extend.php.');
        self::assertIsString($imports['quire-fixture-hello/hello.js'] ?? null, 'The extension\'s script is an entrypoint too.');
        self::assertMatchesRegularExpression('#^/assets/quire-fixture-hello/hello-[\w-]+\.js$#', $imports['quire-fixture-hello/hello.js']);
        self::assertStringContainsString("import 'quire-fixture-hello/hello.js';", $crawler->filter('head script[type=module]')->last()->text());
    }

    public function testFilesThatScriptsImportGetVersionedUrlsAndArePreloaded(): void
    {
        $crawler = $this->client->request('GET', '/blog/hello-world');

        $importMap = json_decode($crawler->filter('head script[type=importmap]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($importMap);
        $imports = $importMap['imports'] ?? [];
        self::assertIsArray($imports);
        $greet = $imports['/assets/quire-fixture-hello/greet.js'] ?? null;
        self::assertIsString($greet, 'The relative import "./greet.js" resolves to a versioned file.');
        self::assertMatchesRegularExpression('#^/assets/quire-fixture-hello/greet-[\w-]+\.js$#', $greet);
        self::assertCount(1, $crawler->filter(\sprintf('head link[rel=modulepreload][href="%s"]', $greet)));
    }

    public function testTemplatesGetTheUrlsOfAssetsAndAClearErrorForMissingOnes(): void
    {
        $twig = self::getContainer()->get(Environment::class);

        self::assertMatchesRegularExpression('#^/assets/quire-fixture-hello/hello-[\w-]+\.css$#', $twig->createTemplate("{{ quire_asset('quire-fixture-hello/hello.css') }}")->render());

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageIsOrContains('No asset "quire-fixture-hello/missing.css". Assets are files in the assets/ directory of the site, of an extension');
        $twig->createTemplate("{{ quire_asset('quire-fixture-hello/missing.css') }}")->render();
    }

    public function testThemePathsNeedATheme(): void
    {
        $this->expectExceptionMessageIsOrContains('No asset "theme/style.css": the site has no theme');

        self::getContainer()->get(Environment::class)->createTemplate("{{ quire_asset('theme/style.css') }}")->render();
    }
}
