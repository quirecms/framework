<?php

declare(strict_types=1);

namespace App\Tests\Release;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The check before a tag (.github/packagist.php): Packagist has every package Split pushes, by its
 * composer.json's name, from Split's repository. Packagist here is a folder of its answers.
 */
final class PackagistTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private string $tmp;

    #[\Override]
    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/quire-packagist-'.bin2hex(random_bytes(6));
        $this->files([
            'framework/.github/split.json' => (string) json_encode([
                ['path' => 'packages/core', 'repository' => 'quirecms/core'],
                ['path' => 'skeleton', 'repository' => 'quirecms/skeleton', 'build' => '.github/skeleton.sh'],
            ]),
            'framework/packages/core/composer.json' => '{"name": "quire/core"}',
            'framework/skeleton/composer.json' => '{"name": "quire/skeleton", "require": "@application"}',
            'packagist/packages/quire/core.json' => '{"package": {"name": "quire/core", "repository": "https://github.com/quirecms/core", "abandoned": false}}',
            'packagist/packages/quire/skeleton.json' => '{"package": {"name": "quire/skeleton", "repository": "https://github.com/QuireCMS/Skeleton.git"}}',
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmp);
    }

    public function testEveryPackageIsThereFromItsRepository(): void
    {
        $process = $this->check();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame("Packagist has every package Split pushes, from its repository: quire/core, quire/skeleton.\n", $process->getOutput());
    }

    public function testThisRepositorysOwnListIsOnPackagist(): void
    {
        // Every package Split pushes has a name Packagist can know it by (the network check is CI's).
        $split = json_decode((string) file_get_contents(self::ROOT.'/.github/split.json'), true);
        self::assertIsArray($split);

        foreach ($split as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['path'] ?? null);
            $composer = json_decode((string) file_get_contents(self::ROOT.'/'.$entry['path'].'/composer.json'), true);
            self::assertIsArray($composer);
            self::assertIsString($composer['name'] ?? null);
            self::assertMatchesRegularExpression('#^quire/[a-z-]+$#', $composer['name']);
        }
    }

    /**
     * @return iterable<string, array{array<string, ?string>, string}>
     */
    public static function problems(): iterable
    {
        yield 'a package Packagist has never had' => [['packagist/packages/quire/core.json' => null], 'Packagist has no quire/core: submit https://github.com/quirecms/core at https://packagist.org/packages/submit before tagging.'];
        yield 'from another repository' => [['packagist/packages/quire/core.json' => '{"package": {"repository": "https://github.com/someone/core"}}'], 'Packagist has quire/core from "https://github.com/someone/core", not from https://github.com/quirecms/core, where Split pushes it.'];
        yield 'abandoned' => [['packagist/packages/quire/core.json' => '{"package": {"repository": "https://github.com/quirecms/core", "abandoned": "quire/kernel"}}'], 'Packagist marks quire/core as abandoned'];
        yield 'an answer that isn\'t one' => [['packagist/packages/quire/core.json' => '<html>'], 'Packagist didn\'t say whether it has quire/core'];
        yield 'known by its composer.json\'s name, not its repository\'s' => [['framework/packages/core/composer.json' => '{"name": "quire/kernel"}'], 'Packagist has no quire/kernel'];
        yield 'a package without a name' => [['framework/packages/core/composer.json' => '{}'], 'packages/core/composer.json has no package name.'];
        yield 'an entry that names nothing' => [['framework/.github/split.json' => '[{"path": "packages/core", "repository": "quirecms/core"}, {"path": "skeleton"}]'], '.github/split.json\'s entry 2 has no path or no repository (owner/name).'];
        yield 'no list' => [['framework/.github/split.json' => '{}'], '.github/split.json lists no packages.'];
    }

    /**
     * @param array<string, ?string> $files Changed (null: removed)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('problems')]
    public function testNothingIsTaggedOtherwise(array $files, string $said): void
    {
        $this->files($files);
        $process = $this->check();

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString($said, $process->getErrorOutput());
        self::assertSame('', $process->getOutput());
    }

    public function testInAWorkflowAsItsErrors(): void
    {
        $this->files(['packagist/packages/quire/core.json' => null]);
        $process = $this->check(['GITHUB_ACTIONS' => 'true']);

        self::assertStringStartsWith('::error::Packagist has no quire/core', $process->getErrorOutput());
    }

    /**
     * @param array<string, string> $env
     */
    private function check(array $env = []): Process
    {
        $process = new Process(['php', self::ROOT.'/.github/packagist.php', '--root='.$this->tmp.'/framework', '--packagist=file://'.$this->tmp.'/packagist'], env: ['GITHUB_ACTIONS' => 'false', ...$env]);
        $process->run();

        return $process;
    }

    /**
     * @param array<string, ?string> $files
     */
    private function files(array $files): void
    {
        $filesystem = new Filesystem();

        foreach ($files as $path => $content) {
            null === $content ? $filesystem->remove($this->tmp.'/'.$path) : $filesystem->dumpFile($this->tmp.'/'.$path, $content);
        }
    }
}
