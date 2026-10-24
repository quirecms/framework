<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Content\Check\ContentChecker;
use Quire\Content\Command\CreateContent;
use Quire\Content\Command\UpdateContent;
use Quire\Content\Command\UpdatePendingRevision;
use Quire\Content\ContentRepository;
use Quire\Content\ContentWriter;
use Quire\Content\Revision\PendingRevisionWriter;
use Quire\Settings\Settings;
use Quire\User\CreateUser;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ContentCommandsTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM quire.content WHERE parent_id IS NOT NULL');
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM quire.content');
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM quire.app_user');
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM quire.setting WHERE name = ?', [ContentChecker::LEVELS_SETTING]);
        self::getContainer()->get(Settings::class)->reset();
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
        $list->execute(['--trashed' => true, '--search' => 'hello']);
        self::assertStringContainsString('Hello again', $list->getDisplay());
        $list->execute(['--trashed' => true, '--search' => 'goodbye']);
        self::assertStringNotContainsString('Hello again', $list->getDisplay());

        self::assertSame(0, $this->command('quire:content:restore')->execute(['id' => $id]));
        self::assertSame(0, $delete->execute(['id' => $id, '--purge' => true, '--force' => true]));
        self::assertNull(self::getContainer()->get(ContentRepository::class)->find($id));
    }

    public function testAuthorsAreGivenByIDEmailOrUsername(): void
    {
        $writer = self::getContainer()->get(UserWriter::class);
        $grace = $writer->create(new CreateUser('Grace Hopper', 'grace@example.com'));
        $ada = $writer->create(new CreateUser('Ada', 'ada@example.com'));
        $repository = self::getContainer()->get(ContentRepository::class);

        $create = $this->command('quire:content:create');
        self::assertSame(0, $create->execute(['type' => 'post', 'title' => 'Notes', '--author' => 'grace@example.com']));
        $id = $this->idFrom($create);
        self::assertSame($grace->id, $repository->get($id)->authorId);

        $update = $this->command('quire:content:update');

        foreach ([$ada->id => $ada->id, 'grace' => $grace->id, '' => null] as $author => $expected) {
            self::assertSame(0, $update->execute(['id' => $id, '--author' => $author]), $update->getDisplay());
            self::assertSame($expected, $repository->get($id)->authorId);
        }

        self::assertSame(1, $create->execute(['type' => 'post', 'title' => 'Ghost', '--author' => 'nobody']));
        self::assertStringContainsString('--author: no user is "nobody".', $this->flat($create));
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

    public function testPendingRevisionsFromTheCommandLine(): void
    {
        $container = self::getContainer();
        $writer = $container->get(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', 'Hours', body: "Monday to Friday.\nClosed on Sundays.\n"));
        $post = $writer->transition($post->id, $post->version, 'publish');
        $pendingWriter = $container->get(PendingRevisionWriter::class);
        $pending = $pendingWriter->create($post->id, message: 'Weekends <too>');
        $pending = $pendingWriter->update(new UpdatePendingRevision($pending->id, 1)->body("Monday to Saturday.\nClosed on Sundays.\n")->field('mood', 'happy'));

        $command = $this->command('quire:content:pending');
        $command->execute(['id' => $post->id]);
        self::assertMatchesRegularExpression('/'.$pending->id.'\s+editing\s+2\s+2\s+\S+ \S+\s+Weekends <too>/', $command->getDisplay());

        $command->execute(['id' => $post->id, '--show' => $pending->id]);
        self::assertStringContainsString("@@ -1,2 +1,2 @@\n-Monday to Friday.\n+Monday to Saturday.\n Closed on Sundays.\n", $command->getDisplay());
        self::assertStringContainsString('fields.mood: (none) → "happy"', $command->getDisplay());

        // Someone changes the same line meanwhile.
        $writer->update(new UpdateContent($post->id, $post->version)->body("Monday to Friday, 9 to 5.\nClosed on Sundays.\n"));
        $command->execute(['id' => $post->id, '--show' => $pending->id]);
        self::assertStringContainsString('body: The same lines were changed', $this->flat($command));
        self::assertSame(1, $command->execute(['id' => $post->id, '--publish' => $pending->id]));
        self::assertStringContainsString('The changes conflict with what was published since they were started: body.', $this->flat($command));

        self::assertSame(0, $command->execute(['id' => $post->id, '--discard' => $pending->id]));
        $other = $pendingWriter->create($post->id);
        $pendingWriter->update(new UpdatePendingRevision($other->id, 1)->title('Opening hours'));

        self::assertSame(1, $command->execute(['id' => $post->id, '--schedule' => $other->id]));
        self::assertStringContainsString('Pass --at with the time to publish the changes, or "none".', $this->flat($command));
        self::assertSame(1, $command->execute(['id' => $post->id, '--schedule' => $other->id, '--at' => '2030-01-01 09:00']));
        self::assertStringContainsString('Give the time with a time zone or offset', $this->flat($command), 'The server\'s time zone isn\'t the site\'s.');
        self::assertSame(0, $command->execute(['id' => $post->id, '--schedule' => $other->id, '--at' => '2030-01-01 09:00 Europe/Berlin']));
        self::assertStringContainsString('The changes will be published at 2030-01-01 08:00', $this->flat($command));
        $command->execute(['id' => $post->id]);
        self::assertStringContainsString('2030-01-01 08:00', $command->getDisplay());
        self::assertSame(0, $command->execute(['id' => $post->id, '--schedule' => $other->id, '--at' => 'none']));
        self::assertStringContainsString('The changes have no time to be published any more.', $this->flat($command));

        self::assertSame(0, $command->execute(['id' => $post->id, '--publish' => $other->id]));
        self::assertStringContainsString('Published the changes. "Opening hours" is now at version 4.', $this->flat($command));
        self::assertSame(1, $command->execute(['id' => $post->id, '--show' => $other->id]));
        self::assertStringContainsString('No pending revision with ID', $this->flat($command));
    }

    public function testContentChecksFromTheCommandLine(): void
    {
        $container = self::getContainer();
        $writer = $container->get(ContentWriter::class);
        $post = $writer->create(new CreateContent('post', 'Pictures', body: "Intro.\n\n![](/chart.png)\n", excerpt: 'In short.'));
        $writer->transition($post->id, $post->version, 'publish');
        $writer->create(new CreateContent('post', 'A draft', body: '![](/draft.png)', excerpt: 'Short.'));

        $command = $this->command('quire:content:check');
        self::assertSame(0, $command->execute(['--type' => 'post']), 'Warnings don\'t fail it.');
        self::assertMatchesRegularExpression('/Pictures\s+\S+\s+warning\s+body, line 3\s+The image “chart.png” has no description/', $command->getDisplay());
        self::assertStringNotContainsString('draft.png', $command->getDisplay(), 'Published and scheduled items by default.');
        self::assertStringContainsString('Checked 1 item: 0 errors, 1 warning.', $this->flat($command));

        self::assertSame(1, $command->execute(['--type' => 'post', '--strict' => true]));
        self::assertSame(0, $command->execute(['--type' => 'post', '--status' => 'draft']));
        self::assertStringContainsString('draft.png', $command->getDisplay());

        self::getContainer()->get(Settings::class)->set(ContentChecker::LEVELS_SETTING, ['core.alt-text' => 'error']);
        self::assertSame(1, $command->execute(['--id' => $post->id]));
        self::assertStringContainsString('Checked 1 item: 1 error, 0 warnings.', $this->flat($command));

        $command->execute(['--list' => true]);
        self::assertMatchesRegularExpression('/core\.alt-text\s+Images have a description\s+error\s+all\s+core/', $command->getDisplay());
        self::assertMatchesRegularExpression('/quire-fixture-hello\.shouting\s+Titles aren\'t shouted\s+warning\s+post\s+quire-fixture-hello/', $command->getDisplay());
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
