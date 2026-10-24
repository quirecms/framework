<?php

declare(strict_types=1);

namespace App\Tests\Packages;

use PHPUnit\Framework\TestCase;

/**
 * Each package, and the skeleton, is copied to its own read-only repository (.github/split.sh), and
 * all of them are released together with the same version. So they must all be listed for the copy,
 * be complete repositories on their own, and agree on the version they're developing.
 */
final class PackagesTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testEveryPackageAndTheSkeletonAreCopiedToTheirOwnRepository(): void
    {
        $directories = array_map(static fn (string $file): string => 'packages/'.basename(\dirname($file)), $this->glob('packages/*/composer.json'));
        $directories[] = 'skeleton';

        $expected = array_map(static fn (string $path): array => ['path' => $path, 'repository' => 'quirecms/'.basename($path)], $directories);
        usort($expected, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        self::assertSame($expected, $this->json('.github/split.json'), 'Every directory of packages/ and the skeleton must be listed in .github/split.json, in order, with the repository named after it.');

        foreach ($directories as $directory) {
            self::assertSame('quire/'.basename($directory), $this->json($directory.'/composer.json')['name'] ?? null);
        }
    }

    public function testEveryCopyClosesPullRequestsAndPointsToThisRepository(): void
    {
        foreach ($this->json('.github/split.json') as $split) {
            self::assertIsArray($split);
            self::assertIsString($split['path']);
            self::assertFileEquals(self::ROOT.'/packages/core/.github/workflows/close-pull-request.yaml', self::ROOT.'/'.$split['path'].'/.github/workflows/close-pull-request.yaml');
            self::assertFileExists(self::ROOT.'/'.$split['path'].'/README.md');
        }
    }

    public function testEveryPackageHasItsLicenseAndLeavesItsTestsOutOfInstalls(): void
    {
        foreach ($this->glob('packages/*', \GLOB_ONLYDIR) as $package) {
            self::assertFileEquals(self::ROOT.'/LICENSE', $package.'/LICENSE');
            self::assertSame("# Left out of the archives that Composer installs\n/.gitattributes export-ignore\n/.github export-ignore\n/tests export-ignore\n", file_get_contents($package.'/.gitattributes'));
        }
    }

    public function testThePackagesDevelopTheSameVersionAndRequireEachOtherInIt(): void
    {
        $version = $this->developmentVersion();
        self::assertMatchesRegularExpression('/^\d+\.\d+\.x-dev$/', $version);
        $constraint = '^'.substr($version, 0, -\strlen('.x-dev'));

        $files = array_map(static fn (string $file): string => substr($file, \strlen(self::ROOT) + 1), $this->glob('packages/*/composer.json'));

        foreach ($files as $file) {
            $extra = $this->json($file)['extra'] ?? null;
            self::assertIsArray($extra, $file);
            self::assertSame(['dev-main' => $version], $extra['branch-alias'] ?? null, \sprintf('%s must alias its main branch to %s.', $file, $version));
        }

        foreach ([...$files, 'skeleton/composer.json', 'composer.json'] as $file) {
            $composer = $this->json($file);

            foreach (['require', 'require-dev'] as $key) {
                $requirements = $composer[$key] ?? [];
                self::assertIsArray($requirements);

                foreach ($requirements as $package => $requirement) {
                    if (str_starts_with((string) $package, 'quire/')) {
                        self::assertSame($constraint, $requirement, \sprintf('%s must require %s in the version that is being developed.', $file, $package));
                    }
                }
            }
        }
    }

    public function testTheTestsInstallTheAdminWithItsOwnManifest(): void
    {
        $fixture = $this->json('tests/Fixtures/installed.json')['packages'] ?? null;
        self::assertIsArray($fixture);
        $admin = array_find($fixture, static fn (mixed $package): bool => \is_array($package) && 'quire/admin' === ($package['name'] ?? null));
        self::assertIsArray($admin);

        $extra = $this->json('packages/admin/composer.json')['extra'] ?? null;
        self::assertIsArray($extra);
        self::assertIsArray($admin['extra'] ?? null);
        self::assertEquals($extra['quire'] ?? null, $admin['extra']['quire'] ?? null, 'tests/Fixtures/installed.json lists quire/admin with the manifest of its composer.json, e.g. the same npm packages.');
    }

    /**
     * @return string The version that the packages are developing, as the path repository of the
     *                application gives it to them, e.g. "0.1.x-dev"
     */
    private function developmentVersion(): string
    {
        $repositories = $this->json('composer.json')['repositories'] ?? null;
        self::assertIsArray($repositories);
        self::assertIsArray($repositories[0] ?? null);
        self::assertSame('packages/*', $repositories[0]['url'] ?? null);
        self::assertIsArray($repositories[0]['options'] ?? null);

        $versions = $repositories[0]['options']['versions'] ?? null;
        self::assertIsArray($versions);

        $names = array_map(fn (string $file): mixed => $this->json(substr($file, \strlen(self::ROOT) + 1))['name'] ?? null, $this->glob('packages/*/composer.json'));
        sort($names);
        self::assertSame($names, array_keys($versions), 'The path repository must give every package its version.');

        $version = array_first($versions);
        self::assertIsString($version);
        self::assertSame(array_fill_keys($names, $version), $versions, 'Every package must develop the same version.');

        return $version;
    }

    /**
     * @return list<string>
     */
    private function glob(string $pattern, int $flags = 0): array
    {
        $paths = glob(self::ROOT.'/'.$pattern, $flags);
        self::assertNotFalse($paths);

        return $paths;
    }

    /**
     * @return array<mixed>
     */
    private function json(string $path): array
    {
        $data = json_decode((string) file_get_contents(self::ROOT.'/'.$path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
