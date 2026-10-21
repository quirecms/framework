<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Quire\Content\Type\Capability;
use Quire\Content\Type\ContentTypeRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ContentTypesFunctionalTest extends KernelTestCase
{
    public function testCoreAndExtensionTypesAreCompiled(): void
    {
        $registry = self::getContainer()->get(ContentTypeRegistry::class);

        self::assertSame(['post', 'page', 'greeting_card'], array_keys($registry->all()));
        self::assertTrue($registry->get('page')->has(Capability::HIERARCHICAL));
        self::assertSame('/blog/{slug}', $registry->get('post')->permalink);
        self::assertTrue($registry->get('post')->has('greetable'), 'The fixture extension added a capability to a core type.');
        self::assertSame('quire-fixture-hello', $registry->get('greeting_card')->owner);
        self::assertSame(['post', 'greeting_card'], array_keys($registry->withCapability(Capability::LISTABLE)));
    }

    public function testListCommand(): void
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('quire:content-type:list'));

        self::assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();

        self::assertMatchesRegularExpression('/post\s+Posts\s+revisions, scheduling, excerpt, listable, searchable, author, greetable\s+\/blog\/\{slug\}\s+published_at desc\s+core/', $display);
        self::assertMatchesRegularExpression('/page\s+Pages\s+hierarchical, revisions, searchable, author\s+\/\{path\}\s+position asc\s+core/', $display);
        self::assertMatchesRegularExpression('/greeting_card\s+Greeting cards\s+listable, printable\s+\/cards\/\{id\}\s+published_at desc\s+quire-fixture-hello/', $display);
    }
}
