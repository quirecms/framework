<?php

declare(strict_types=1);

namespace App\Tests\Skeleton;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The starter project in skeleton/ (quire/skeleton) is the application of this repository without what
 * only Quire's own development needs. Its files must stay the same as the application's, so that what
 * runs and is tested here is what new sites get.
 */
final class SkeletonTest extends TestCase
{
    /** Files only the skeleton has */
    private const array SKELETON_ONLY = ['.github/workflows/close-pull-request.yaml', 'composer.json', 'README.md', 'symfony.lock'];

    /** Files that differ on purpose, with what the application has in addition */
    private const array DIFFERENT = [
        '.dockerignore' => 'the tests of the packages, and the skeleton',
        '.env.dev' => 'a fixed secret; new sites get their own when they are created',
        '.gitignore' => 'the files of PHPUnit and PHP-CS-Fixer',
        'Dockerfile' => 'the packages, which it installs from path repositories',
        'compose.override.yaml' => 'an S3 service for the tests of storage disks',
        'compose.yaml' => 'a fixed project name; sites are named after their directory',
    ];

    /** Files only the application has: those of the tests and of the development tools */
    private const array APPLICATION_ONLY = ['.env.test', 'bin/phpunit', 'config/packages/test/quire.yaml', 'config/services_dev.yaml', 'config/services_test.yaml'];

    /** The parts of this repository that make up the application */
    private const array APPLICATION_DIRECTORIES = ['assets', 'bin', 'config', 'frankenphp', 'migrations', 'public', 'src', 'templates', 'translations'];
    private const array APPLICATION_FILES = [
        '.dockerignore', '.editorconfig', '.env', '.env.dev', '.env.test', '.gitattributes', '.gitignore',
        'Dockerfile', 'compose.override.yaml', 'compose.prod.yaml', 'compose.yaml', 'extend.php', 'importmap.php',
    ];

    private const string ROOT = __DIR__.'/../..';
    private const string SKELETON = self::ROOT.'/skeleton';

    public function testTheSkeletonsFilesAreTheApplicationsUnlessTheyDifferOnPurpose(): void
    {
        foreach ($this->files(self::SKELETON, ['.']) as $path) {
            if (\in_array($path, self::SKELETON_ONLY, true)) {
                continue;
            }

            self::assertFileExists(self::ROOT.'/'.$path, \sprintf('skeleton/%s has no counterpart in the application: add it there, or list it as a file only the skeleton has.', $path));

            if (\array_key_exists($path, self::DIFFERENT)) {
                self::assertFileNotEquals(self::ROOT.'/'.$path, self::SKELETON.'/'.$path, \sprintf('%s is now the same in the skeleton: remove it from the files that differ.', $path));

                continue;
            }

            self::assertFileEquals(self::ROOT.'/'.$path, self::SKELETON.'/'.$path, \sprintf('skeleton/%s differs from the application\'s: change both, or list it as a file that differs.', $path));
        }
    }

    public function testTheSkeletonHasEveryFileOfTheApplication(): void
    {
        $missing = [];

        foreach ($this->files(self::ROOT, self::APPLICATION_DIRECTORIES) as $path) {
            if (!\in_array($path, self::APPLICATION_ONLY, true) && !is_file(self::SKELETON.'/'.$path)) {
                $missing[] = $path;
            }
        }

        foreach (self::APPLICATION_FILES as $path) {
            if (!\in_array($path, self::APPLICATION_ONLY, true) && !is_file(self::SKELETON.'/'.$path)) {
                $missing[] = $path;
            }
        }

        self::assertSame([], $missing, 'The skeleton lacks these files of the application: copy them, or list them as files only the application has.');
    }

    public function testTheSkeletonRequiresWhatTheApplicationRequires(): void
    {
        $application = $this->json(self::ROOT.'/composer.json');
        $skeleton = $this->json(self::SKELETON.'/composer.json');

        foreach (['require', 'replace', 'conflict', 'extra'] as $key) {
            self::assertSame($application[$key] ?? null, $skeleton[$key] ?? null, \sprintf('"%s" of skeleton/composer.json differs from the application\'s.', $key));
        }

        self::assertIsArray($application['scripts'] ?? null);
        self::assertIsArray($skeleton['scripts'] ?? null);
        self::assertSame($application['scripts']['auto-scripts'] ?? null, $skeleton['scripts']['auto-scripts'] ?? null, 'The skeleton runs other scripts after installing than the application.');
    }

    public function testTheSkeletonsRecipesAreTheApplications(): void
    {
        // Symfony Flex installs the recipes of packages that symfony.lock doesn't list, which would add
        // or change files of new sites. So it must list the recipes the application has, as they are.
        $application = $this->json(self::ROOT.'/symfony.lock');
        $development = $this->json(self::ROOT.'/composer.json')['require-dev'] ?? null;
        self::assertIsArray($development);

        self::assertSame(array_diff_key($application, $development), $this->json(self::SKELETON.'/symfony.lock'));
    }

    public function testNewSitesGetTheirOwnSecret(): void
    {
        self::assertStringContainsString("APP_SECRET=\n", (string) file_get_contents(self::SKELETON.'/.env.dev'));

        $scripts = $this->json(self::SKELETON.'/composer.json')['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertStringContainsString('random_bytes', json_encode($scripts['post-create-project-cmd'] ?? null, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $directories
     *
     * @return list<string> Paths of the files in those directories that Git doesn't ignore, relative to $root
     */
    private function files(string $root, array $directories): array
    {
        $finder = new Finder()
            ->files()
            ->in(array_map(static fn (string $directory): string => $root.'/'.$directory, $directories))
            ->ignoreDotFiles(false)
            ->ignoreVCSIgnored(true);

        $root = (string) realpath($root);
        $paths = [];

        foreach ($finder as $file) {
            $paths[] = substr((string) $file->getRealPath(), \strlen($root) + 1);
        }

        sort($paths);

        return $paths;
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
