<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\ContentRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ContentCommandsTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM quire.content WHERE parent_id IS NOT NULL');
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM quire.content');
        parent::tearDown();
    }

    public function testALifecycleFromTheCommandLine(): void
    {
        $create = $this->command('quire:content:create');
        self::assertSame(0, $create->execute(['type' => 'post', 'title' => 'Hello world', '--body' => 'Hi', '--fields' => '{"mood": "happy"}', '--publish' => true]));
        self::assertMatchesRegularExpression('/Created post "Hello world" \(hello-world\) as published, with ID [0-9a-f-]{36}\./', $this->flat($create));
        $id = $this->idFrom($create);

        $update = $this->command('quire:content:update');
        self::assertSame(0, $update->execute(['id' => $id, '--title' => 'Hello again', '--message' => 'Retitled', '--fields' => '{"mood": null}']));
        self::assertStringContainsString('Saved "Hello again", now at version 3.', $this->flat($update));

        $show = $this->command('quire:content:show');
        $show->execute(['id' => $id]);
        self::assertMatchesRegularExpression('/Status\s+published/', $show->getDisplay());
        self::assertMatchesRegularExpression('/Transitions\s+unpublish, archive/', $show->getDisplay());
        self::assertMatchesRegularExpression('/Slug\s+hello-world/', $show->getDisplay());

        $revisions = $this->command('quire:content:revisions');
        $revisions->execute(['id' => $id]);
        self::assertMatchesRegularExpression('/3\s+\S+ \S+\s+Hello again\s+Retitled/', $revisions->getDisplay());

        $transition = $this->command('quire:content:transition');
        $transition->execute(['id' => $id]);
        self::assertStringContainsString('The item is published. Possible transitions: unpublish, archive.', $transition->getDisplay());
        self::assertSame(1, $transition->execute(['id' => $id, 'transition' => 'publish']));
        self::assertStringContainsString('A published item can\'t take the "publish" transition. Possible now: unpublish, archive.', $this->flat($transition));

        $delete = $this->command('quire:content:delete');
        self::assertSame(0, $delete->execute(['id' => $id]));
        $list = $this->command('quire:content:list');
        $list->execute(['--trashed' => true]);
        self::assertStringContainsString('Hello again', $list->getDisplay());

        self::assertSame(0, $this->command('quire:content:restore')->execute(['id' => $id]));
        self::assertSame(0, $delete->execute(['id' => $id, '--purge' => true, '--force' => true]));
        self::assertNull(self::getContainer()->get(ContentRepository::class)->find($id));
    }

    public function testErrorsNameTheProperty(): void
    {
        $create = $this->command('quire:content:create');

        self::assertSame(1, $create->execute(['type' => 'page', 'title' => 'About', '--excerpt' => 'x', '--locale' => 'english']));
        self::assertStringContainsString('locale: Use a language tag such as "en", "de" or "pt-BR".', $this->flat($create));
        self::assertStringContainsString('excerpt: Pages have no excerpt.', $this->flat($create));

        self::assertSame(1, $create->execute(['type' => 'post', 'title' => 'X', '--fields' => '[1]']));
        self::assertStringContainsString('--fields takes a JSON object', $this->flat($create));
    }

    public function testScheduleFromTheCommandLine(): void
    {
        $create = $this->command('quire:content:create');
        self::assertSame(0, $create->execute(['type' => 'post', 'title' => 'Launch', '--schedule' => '+1 day']));
        self::assertStringContainsString('as scheduled', $this->flat($create));

        $due = $this->command('quire:content:publish-due');
        $due->execute([]);
        self::assertStringContainsString('Published 0 scheduled items.', $due->getDisplay());
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::bootKernel())->find($name));
    }

    /**
     * The output on one line, without the line breaks and padding of SymfonyStyle blocks.
     */
    private function flat(CommandTester $tester): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(['[OK]', '[ERROR]'], '', $tester->getDisplay())));
    }

    private function idFrom(CommandTester $tester): string
    {
        self::assertSame(1, preg_match('/with ID ([0-9a-f-]{36})/', $this->flat($tester), $matches));

        return $matches[1];
    }
}
