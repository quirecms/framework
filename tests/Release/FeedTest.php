<?php

declare(strict_types=1);

namespace App\Tests\Release;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Quire's release feed (.github/feed.php), built from throwaway repositories with their tags: what
 * each release says, and the notes it refuses (a feed tools trust must say only what's meant).
 */
final class FeedTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private string $tmp;
    private string $repository;
    private string $published;
    private string $skeleton;
    private string $split;
    private string $remote;

    /** @var array<string, string> Each package's commit of 0.1.0, in its repository (theme-default, privacy and analytics had none then) */
    private array $tagged = [];

    #[\Override]
    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/quire-feed-'.bin2hex(random_bytes(6));
        $this->repository = $this->tmp.'/framework';
        $this->published = $this->tmp.'/skeleton';
        $this->skeleton = $this->tmp.'/built';

        // The framework: 0.1.0, then 0.1.1 with the server contract and PostgreSQL 18
        $this->files($this->repository, [
            'composer.json' => '{"require": {"php": ">=8.5.0"}}',
            'compose.yaml' => "services:\n  database:\n    image: postgres:\${POSTGRES_VERSION:-17}-alpine\n",
            'docs/releases/0.1.0.md' => "---\nsecurity: []\nupgrade: null\n---\nThe first release.\n",
        ]);
        $this->git($this->repository, ['init', '-q', '-b', 'main']);
        $this->commit($this->repository, 'v0.1.0', '2026-10-01T12:00:00+00:00');
        $this->files($this->repository, [
            'composer.json' => '{"require": {"php": ">=8.5.11"}}',
            'compose.yaml' => "services:\n  database:\n    image: postgres:\${POSTGRES_VERSION:-18}-alpine\n",
            'packages/core/src/Runtime/ServerContract.php' => "<?php\nfinal class ServerContract\n{\n    public const int VERSION = 1;\n}\n",
            'docs/releases/0.1.1.md' => <<<'MD'
                ---
                security:
                  - id: GHSA-2c3f-4g5h-6j7m
                    severity: high
                    package: quire/core
                    summary: A draft could be read by its link.
                upgrade: |
                  Run `composer update "quire/*"`.
                ---
                Drafts stay drafts.

                - And a list.
                MD,
        ]);
        $this->commit($this->repository, 'v0.1.1', '2026-10-20T12:00:00+00:00');

        // The published skeleton of 0.1.0, and 0.1.1's as built
        $this->files($this->published, ['Dockerfile' => 'FROM 0.1.0', 'compose.yaml' => 'same', 'old.txt' => 'removed later']);
        $this->git($this->published, ['init', '-q', '-b', 'main']);
        $this->commit($this->published, 'v0.1.0', '2026-10-01T12:00:00+00:00');
        $this->files($this->skeleton, ['Dockerfile' => 'FROM 0.1.1', 'compose.yaml' => 'same', 'new.txt' => 'added', 'bin/console' => '#!/usr/bin/env php']);

        // The packages' repositories (SPLIT_REMOTE), with 0.1.0's tags; and what split.sh says of 0.1.1
        $this->remote = $this->tmp.'/remote';
        $this->split = $this->tmp.'/split.txt';
        $lines = [];

        foreach (['admin' => 'quire/admin', 'analytics' => 'quire/analytics', 'core' => 'quire/core', 'privacy' => 'quire/privacy', 'skeleton' => 'quire/skeleton', 'testing' => 'quire/testing', 'theme-default' => 'quire/theme-default'] as $repository => $name) {
            $directory = $this->remote.'/quirecms/'.$repository.'.git';
            $this->files($directory, ['README.md' => $repository]);
            $this->git($directory, ['init', '-q', '-b', 'main']);

            if (!\in_array($repository, ['analytics', 'privacy', 'theme-default'], true)) {
                $this->commit($directory, 'v0.1.0', '2026-10-01T12:00:00+00:00');
                $this->tagged[$name] = trim(new Process(['git', 'rev-parse', 'v0.1.0^{commit}'], $directory)->mustRun()->getOutput());
            }

            $lines[] = \sprintf('%s at %s: quirecms/%s %s', 'skeleton' === $repository ? 'skeleton' : 'packages/'.$repository, str_repeat('a', 40), $repository, hash('sha1', $name));
        }

        $this->files($this->tmp, ['split.txt' => implode("\n", $lines)."\n"]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmp);
    }

    public function testTheFeedHasEveryReleaseNewestFirst(): void
    {
        $feed = $this->feed();

        self::assertSame([1, 'Quire', 'quirecms/framework'], [$feed['schema'], $feed['name'], $feed['repository']]);
        self::assertSame(['0.1.1', '0.1.0'], array_column($feed['releases'], 'version'));

        [$latest, $first] = $feed['releases'];
        self::assertSame('2026-10-20', $latest['date']);
        self::assertSame("Drafts stay drafts.\n\n- And a list.", $latest['notes']);
        self::assertSame('Run `composer update "quire/*"`.', $latest['upgrade']);
        self::assertSame([['id' => 'GHSA-2c3f-4g5h-6j7m', 'severity' => 'high', 'package' => 'quire/core', 'summary' => 'A draft could be read by its link.']], $latest['security']);
        self::assertSame(['php' => '>=8.5.11', 'postgresql' => 18, 'server_contract' => 1], $latest['requires']);

        self::assertSame([], $first['security']);
        self::assertNull($first['upgrade']);
        self::assertSame(['php' => '>=8.5.0', 'postgresql' => 17, 'server_contract' => null], $first['requires'], 'Before 0.1.1, no contract.');
    }

    public function testItSaysTheSkeletonsFilesAndWhichChanged(): void
    {
        [$latest, $first] = $this->feed()['releases'];

        self::assertSame(['Dockerfile' => hash('sha256', 'FROM 0.1.0'), 'compose.yaml' => hash('sha256', 'same'), 'old.txt' => hash('sha256', 'removed later')], $first['skeleton']['files']);
        self::assertNull($first['skeleton']['changed']);
        self::assertSame(['Dockerfile', 'bin/console', 'compose.yaml', 'new.txt'], array_keys($latest['skeleton']['files']));
        self::assertSame(['Dockerfile', 'bin/console', 'new.txt', 'old.txt'], $latest['skeleton']['changed'], 'Added, changed and removed; not the one that stayed.');
    }

    public function testItSaysEachPackagesCommitInItsRepository(): void
    {
        [$latest, $first] = $this->feed()['releases'];

        self::assertSame(['quire/admin' => hash('sha1', 'quire/admin'), 'quire/analytics' => hash('sha1', 'quire/analytics'), 'quire/core' => hash('sha1', 'quire/core'), 'quire/privacy' => hash('sha1', 'quire/privacy'), 'quire/testing' => hash('sha1', 'quire/testing'), 'quire/theme-default' => hash('sha1', 'quire/theme-default')], $latest['packages'], 'This version\'s, as the split makes them; not the skeleton\'s (its files vouch for it).');
        unset($this->tagged['quire/skeleton']);
        ksort($this->tagged);
        self::assertSame($this->tagged, $first['packages'], 'Earlier ones\' from their tags (annotated: the commit, not the tag); one without the tag is left out.');
    }

    public function testEveryPackagesCommitIsNeeded(): void
    {
        // admin, analytics, core and privacy
        $this->files($this->tmp, ['split.txt' => implode("\n", \array_slice(explode("\n", (string) file_get_contents($this->split)), 0, 4))."\n"]);
        $this->refused('lacks quire/testing, quire/theme-default');
    }

    public function testALineThatIsntTheSplitsIsRefused(): void
    {
        $this->files($this->tmp, ['split.txt' => "packages/core at main: quirecms/core v0.1.1\n"]);
        $this->refused('isn\'t a line of .github/split.sh\'s');
    }

    public function testBuildingItAgainGivesTheSameFile(): void
    {
        self::assertSame($this->build()->getOutput(), $this->build()->getOutput());
    }

    public function testItNeedsTheReleasesNotes(): void
    {
        $this->refused('There are no release notes for 0.1.2', version: '0.1.2');
    }

    public function testNotesOfAReleaseThatIsNotTaggedAreRefused(): void
    {
        $this->files($this->repository, ['docs/releases/0.1.2.md' => "---\nsecurity: []\nupgrade: null\n---\nSoon.\n"]);
        $this->refused('has no tag v0.1.2', version: '0.1.2');
    }

    public function testLaterReleasesAreLeftOut(): void
    {
        $this->files($this->repository, ['docs/releases/0.2.0.md' => "---\nsecurity: []\nupgrade: null\n---\nLater.\n"]);
        self::assertSame(['0.1.1', '0.1.0'], array_column($this->feed()['releases'], 'version'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badNotes(): iterable
    {
        yield 'no header' => ["Just notes.\n", 'must start with its header'];
        yield 'an unknown key' => ["---\nsecurity: []\nupgrade: null\nbreaking: true\n---\nNotes.\n", 'not "breaking"'];
        yield 'no security key' => ["---\nupgrade: null\n---\nNotes.\n", 'exactly "security"'];
        yield 'security not a list' => ["---\nsecurity: yes\nupgrade: null\n---\nNotes.\n", 'must be a list'];
        yield 'an advisory without a summary' => ["---\nsecurity:\n  - {id: GHSA-2c3f-4g5h-6j7m, severity: high, package: quire/core}\nupgrade: null\n---\nNotes.\n", 'needs "summary"'];
        yield 'an advisory that is not one' => ["---\nsecurity:\n  - {id: 'BUG-1', severity: high, package: quire/core, summary: x}\nupgrade: null\n---\nNotes.\n", 'isn\'t a GitHub advisory'];
        yield 'an unknown severity' => ["---\nsecurity:\n  - {id: CVE-2026-12345, severity: urgent, package: quire/core, summary: x}\nupgrade: null\n---\nNotes.\n", 'its severity is one of'];
        yield 'a package that is not Quire\'s' => ["---\nsecurity:\n  - {id: CVE-2026-12345, severity: low, package: symfony/http-kernel, summary: x}\nupgrade: null\n---\nNotes.\n", 'one of Quire\'s'];
        yield 'an empty upgrade' => ["---\nsecurity: []\nupgrade: ''\n---\nNotes.\n", '"upgrade" is text, or null'];
        yield 'no notes' => ["---\nsecurity: []\nupgrade: null\n---\n\n", 'has no notes'];
        yield 'not YAML' => ["---\nsecurity: [\nupgrade: null\n---\nNotes.\n", 'isn\'t YAML'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badNotes')]
    public function testNotesThatSayLessOrMoreThanMeantAreRefused(string $notes, string $message): void
    {
        $this->files($this->repository, ['docs/releases/0.1.1.md' => $notes]);
        $this->refused($message);
    }

    public function testTheReleasesTextHasItsSecurityFixesAndUpgradeNotes(): void
    {
        $process = new Process(['php', self::ROOT.'/.github/feed.php', '--version=0.1.1', '--release-notes', '--root='.$this->repository]);
        $process->mustRun();

        self::assertSame(<<<'MD'
            Drafts stay drafts.

            - And a list.

            ## Security fixes

            - **GHSA-2c3f-4g5h-6j7m** (high, quire/core): A draft could be read by its link.

            ## Upgrading

            Run `composer update "quire/*"`.

            MD, $process->getOutput());
    }

    public function testTheChangelogHasEveryReleasesTextNewestFirst(): void
    {
        $process = new Process(['php', self::ROOT.'/.github/feed.php', '--changelog', '--root='.$this->repository]);
        $process->mustRun();
        $changelog = $process->getOutput();

        self::assertStringStartsWith("# Changelog\n", $changelog);
        self::assertStringContainsString("## [0.1.1](https://github.com/quirecms/framework/releases/tag/v0.1.1)\n\nDrafts stay drafts.", $changelog);
        self::assertStringContainsString("### Security fixes\n\n- **GHSA-2c3f-4g5h-6j7m**", $changelog, 'One level under the version.');
        self::assertStringContainsString("### Upgrading\n\nRun `composer update \"quire/*\"`.", $changelog);
        preg_match_all('/^## \[([^\]]+)\]/m', $changelog, $versions);
        $sorted = $versions[1];
        usort($sorted, static fn (string $a, string $b): int => version_compare($b, $a));
        self::assertSame($sorted, $versions[1]);
    }

    public function testQuiresChangelogIsUpToDate(): void
    {
        $process = new Process(['php', self::ROOT.'/.github/feed.php', '--changelog']);
        $process->mustRun();

        self::assertSame($process->getOutput(), file_get_contents(self::ROOT.'/CHANGELOG.md'), 'CHANGELOG.md is made from docs/releases: "php .github/feed.php --changelog > CHANGELOG.md".');
    }

    public function testQuiresOwnReleaseNotesAreValid(): void
    {
        $files = iterator_to_array(new Finder()->files()->in(self::ROOT.'/docs/releases')->name('*.md'));
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $process = new Process(['php', self::ROOT.'/.github/feed.php', '--version='.$file->getBasename('.md'), '--release-notes']);
            $process->run();
            self::assertTrue($process->isSuccessful(), $file->getFilename().': '.$process->getErrorOutput());
        }
    }

    /**
     * @return array{schema: int, name: string, repository: string, releases: list<array{version: string, date: string, security: list<array<string, string>>, notes: string, upgrade: ?string, requires: array{php: string, postgresql: ?int, server_contract: ?int}, skeleton: array{files: array<string, string>, changed: ?list<string>}, packages: array<string, string>}>}
     */
    private function feed(): array
    {
        /** @var array{schema: int, name: string, repository: string, releases: list<array{version: string, date: string, security: list<array<string, string>>, notes: string, upgrade: ?string, requires: array{php: string, postgresql: ?int, server_contract: ?int}, skeleton: array{files: array<string, string>, changed: ?list<string>}, packages: array<string, string>}>} $feed The feed's shape (the tests check its values) */
        $feed = json_decode($this->build()->getOutput(), true, flags: \JSON_THROW_ON_ERROR);

        return $feed;
    }

    private function build(string $version = '0.1.1'): Process
    {
        $process = $this->process($version);
        $process->mustRun();

        return $process;
    }

    private function refused(string $message, string $version = '0.1.1'): void
    {
        $process = $this->process($version);
        $process->run();

        self::assertFalse($process->isSuccessful(), 'The feed was built.');
        self::assertSame('', $process->getOutput(), 'Nothing of it is written.');
        self::assertStringContainsString($message, $process->getErrorOutput());
    }

    private function process(string $version): Process
    {
        return new Process(['php', self::ROOT.'/.github/feed.php', '--version='.$version, '--skeleton='.$this->skeleton, '--published='.$this->published, '--packages='.$this->split, '--root='.$this->repository], null, ['SPLIT_REMOTE' => $this->remote]);
    }

    /**
     * @param array<string, string> $files
     */
    private function files(string $directory, array $files): void
    {
        foreach ($files as $path => $content) {
            new Filesystem()->dumpFile($directory.'/'.$path, $content);
        }
    }

    private function commit(string $directory, string $tag, string $date): void
    {
        $this->git($directory, ['add', '-A']);
        $this->git($directory, ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', $tag], ['GIT_AUTHOR_DATE' => $date, 'GIT_COMMITTER_DATE' => $date]);
        $this->git($directory, ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'tag', '-a', $tag, '-m', $tag]);
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $env
     */
    private function git(string $directory, array $arguments, array $env = []): void
    {
        new Process(['git', ...$arguments], $directory, $env)->mustRun();
    }
}
