<?php

declare(strict_types=1);

namespace App\Tests\Skeleton;

use PHPUnit\Framework\TestCase;
use Quire\Runtime\ServerContract;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * The starter project quire/skeleton is built from the application of this repository
 * (.github/skeleton.sh): its files exist once, here, so what runs and is tested here is what new sites
 * get. These tests build it and check what it has, and what it must not have of the development.
 */
final class SkeletonTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** What's at the root of this repository only for Quire's own development (never in the skeleton) */
    private const array DEVELOPMENT = [
        '.env.test', '.github', '.php-cs-fixer.dist.php', 'CONTRIBUTING.md', 'LICENSE', 'README.md', 'SECURITY.md',
        'composer.json', 'composer.lock', 'docs', 'packages', 'phpstan.dist.neon', 'phpunit.dist.xml', 'rector.php',
        'runtime', 'skeleton', 'symfony.lock', 'tests',
    ];

    /** The application's files only the development needs */
    private const array APPLICATION_ONLY = ['.env.test', 'bin/phpunit', 'config/packages/test/quire.yaml', 'config/services_dev.yaml', 'config/services_test.yaml'];

    private static string $skeleton;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$skeleton = sys_get_temp_dir().'/quire-skeleton-'.bin2hex(random_bytes(6));
        new Process(['bash', self::ROOT.'/.github/skeleton.sh', self::$skeleton])->mustRun();
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::$skeleton);
    }

    public function testEveryFileOfTheApplicationIsInTheSkeleton(): void
    {
        $missing = [];

        foreach ($this->tracked() as $path) {
            $top = explode('/', $path)[0];

            if (\in_array($top, self::DEVELOPMENT, true) || \in_array($path, self::APPLICATION_ONLY, true)) {
                continue;
            }

            if (!is_file(self::$skeleton.'/'.$path)) {
                $missing[] = $path;
            }
        }

        self::assertSame([], $missing, 'New sites wouldn\'t get these files of the application: add them to .github/skeleton.sh, or list them as development only here (and there).');
    }

    public function testNothingOfTheDevelopmentIsInTheSkeleton(): void
    {
        foreach (self::APPLICATION_ONLY as $path) {
            self::assertFileDoesNotExist(self::$skeleton.'/'.$path);
        }

        foreach ($this->files(self::$skeleton) as $path) {
            $content = (string) file_get_contents(self::$skeleton.'/'.$path);
            self::assertStringNotContainsString('development only', $content, $path.' has a "development only" marker left.');
            self::assertStringNotContainsString('@application', 'composer.json' === $path ? $content : '', 'composer.json has a value it didn\'t get from the application.');
        }

        self::assertStringNotContainsString('packages/', (string) file_get_contents(self::$skeleton.'/Dockerfile'), 'New sites install Quire from Packagist, not from path repositories.');
        self::assertArrayNotHasKey('name', $this->compose('compose.yaml'), 'Sites are named after their directory.');
        self::assertArrayNotHasKey('s3', $this->services('compose.override.yaml'), 'The S3 service is for the tests of the packages.');
        self::assertDirectoryDoesNotExist(self::$skeleton.'/tests');
    }

    public function testTheSkeletonRequiresWhatTheApplicationRequires(): void
    {
        $application = $this->json(self::ROOT.'/composer.json');
        $skeleton = $this->json(self::$skeleton.'/composer.json');

        self::assertSame('quire/skeleton', $skeleton['name']);
        self::assertSame('project', $skeleton['type']);

        foreach (['require', 'replace', 'conflict', 'extra'] as $key) {
            self::assertSame($application[$key] ?? null, $skeleton[$key] ?? null, $key);
        }

        self::assertArrayNotHasKey('require-dev', $skeleton);
        self::assertArrayNotHasKey('repositories', $skeleton, 'New sites install Quire from Packagist.');
    }

    public function testTheSkeletonsRecipesAreTheApplicationsButThoseOfDevelopmentTools(): void
    {
        // Symfony Flex installs the recipes of packages that symfony.lock doesn't list, which would add
        // or change files of new sites. So it must list the recipes the application has, as they are.
        $application = $this->json(self::ROOT.'/symfony.lock');
        $development = $this->json(self::ROOT.'/composer.json')['require-dev'] ?? null;
        self::assertIsArray($development);

        self::assertSame(array_diff_key($application, $development), $this->json(self::$skeleton.'/symfony.lock'));
    }

    public function testSitesSayWhichServerContractTheyKeep(): void
    {
        /** @var array{services: array{php: array{labels: array<string, string>}}} $compose */
        $compose = $this->compose('compose.yaml');

        self::assertSame('true', $compose['services']['php']['labels']['com.quirecms.site']);
        self::assertSame((string) ServerContract::VERSION, $compose['services']['php']['labels']['com.quirecms.contract'], 'The label and quire:about agree.');
    }

    public function testNewSitesGetTheirOwnSecret(): void
    {
        self::assertStringContainsString("\nAPP_SECRET=\n", (string) file_get_contents(self::$skeleton.'/.env.dev'));
        self::assertStringNotContainsString('d2ddca55', (string) file_get_contents(self::$skeleton.'/.env.dev'), 'Not the development\'s secret.');

        $scripts = $this->json(self::$skeleton.'/composer.json')['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertStringContainsString('random_bytes', json_encode($scripts['post-create-project-cmd'] ?? null, \JSON_THROW_ON_ERROR));
    }

    public function testItsCommandsStayExecutable(): void
    {
        self::assertTrue(is_executable(self::$skeleton.'/bin/console'));
    }

    /**
     * @return list<string> The files Git has, relative to the root
     */
    private function tracked(): array
    {
        $process = new Process(['git', 'ls-files', '-z'], self::ROOT);
        $process->mustRun();

        return array_values(array_filter(explode("\0", $process->getOutput()), static fn (string $path): bool => '' !== $path));
    }

    /**
     * @return list<string> The files under a directory, relative to it
     */
    private function files(string $root): array
    {
        $paths = [];

        foreach (new Finder()->files()->in($root)->ignoreDotFiles(false) as $file) {
            $paths[] = $file->getRelativePathname();
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return array<mixed> A Compose file of the skeleton
     */
    private function compose(string $file): array
    {
        $compose = Yaml::parseFile(self::$skeleton.'/'.$file);
        self::assertIsArray($compose);

        return $compose;
    }

    /**
     * @return array<mixed> Its services
     */
    private function services(string $file): array
    {
        $services = $this->compose($file)['services'] ?? null;
        self::assertIsArray($services);

        return $services;
    }

    /**
     * @return array<mixed>
     */
    private function json(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
