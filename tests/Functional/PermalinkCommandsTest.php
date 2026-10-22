<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Command\CreateContent;
use Quire\Content\ContentWriter;
use Quire\Content\Permalink\Redirects;
use Quire\Content\Status;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class PermalinkCommandsTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testListsPatternsInMatchingOrder(): void
    {
        $list = $this->command('quire:permalink:list');
        self::assertSame(0, $list->execute([]));

        self::assertMatchesRegularExpression('#greeting_card\s+/cards/\{id\}\s+/cards.*post\s+/blog/\{slug\}\s+/\s.*page\s+/\{path\}\s+–#s', $list->getDisplay());
        self::assertStringContainsString('Homepage: the list of posts', $list->getDisplay());
        self::assertStringNotContainsString('compete', $list->getDisplay());
    }

    public function testAddsListsAndRemovesRedirects(): void
    {
        $writer = self::getContainer()->get(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', 'Hello'));
        $writer->transition($post->id, $post->version, Status::PUBLISH, new \DateTimeImmutable('-1 hour'));

        $add = $this->command('quire:redirect:add');
        self::assertSame(0, $add->execute(['from' => '/2019/05/Hello/', 'to' => '/blog/hello']));
        self::assertStringContainsString('/2019/05/hello now redirects to /blog/hello.', $this->flat($add));
        self::assertSame($post->id, self::getContainer()->get(Redirects::class)->find('/2019/05/hello')?->targetContentId, 'Stored as the item, to follow it.');

        self::assertSame(1, $add->execute(['from' => '/blog/hello', 'to' => '/elsewhere']));
        self::assertStringContainsString('already shows published content', $this->flat($add));
        self::assertSame(1, $add->execute(['from' => '/old', 'to' => 'elsewhere']));
        self::assertStringContainsString('must be a path starting with "/" or a URL', $this->flat($add));

        $list = $this->command('quire:redirect:list');
        $list->execute([]);
        self::assertMatchesRegularExpression('#/2019/05/hello\s+/blog/hello \(post "Hello"\)\s+301#', $list->getDisplay());

        $remove = $this->command('quire:redirect:remove');
        self::assertSame(0, $remove->execute(['from' => '/2019/05/hello']));
        self::assertSame(1, $remove->execute(['from' => '/2019/05/hello']));
    }

    public function testImportsRedirectsFromCsv(): void
    {
        $file = sys_get_temp_dir().'/quire-redirects-'.bin2hex(random_bytes(4)).'.csv';
        new Filesystem()->dumpFile($file, "source,target,status\nhttps://old.example.com/about-us/,/about\n/shop,https://shop.example.com,302\n/broken\n/x,/y,999\n");

        try {
            $import = $this->command('quire:redirect:import');
            self::assertSame(1, $import->execute(['file' => $file]));
        } finally {
            new Filesystem()->remove($file);
        }

        self::assertStringContainsString('Line 4: expected "from,to" or "from,to,status".', $this->flat($import));
        self::assertStringContainsString('Line 5 (/x): The status must be one of 301, 302, 307, 308.', $this->flat($import));
        self::assertStringContainsString('Added 2 redirect(s).', $this->flat($import));
        self::assertSame(302, self::getContainer()->get(Redirects::class)->find('/shop')?->status);
        self::assertSame('/about', self::getContainer()->get(Redirects::class)->find('/about-us')?->targetUrl);
    }

    private function command(string $name): CommandTester
    {
        // The kernel booted in setUp(), so commands share the test's database transaction.
        \assert(self::$kernel instanceof \Symfony\Component\HttpKernel\KernelInterface);

        return new CommandTester(new Application(self::$kernel)->find($name));
    }

    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
