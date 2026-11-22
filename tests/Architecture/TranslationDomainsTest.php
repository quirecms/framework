<?php

declare(strict_types=1);

namespace Quire\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Quire\Extension\ManifestParser;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * ADR-0009 (D-52): one translation domain per package, and a package's translations/ holds only its own.
 * Texts in another package's domain would be that package's texts, edited by whoever ships the file, and
 * a language artifact made for one package couldn't be told from one that reaches into another. Core
 * alone also holds Symfony's own domains, whose messages Quire words its way (e.g. a wrong password).
 */
final class TranslationDomainsTest extends TestCase
{
    /** Symfony's domains core words its own way; never a package's, and never an artifact's */
    private const array SYMFONY_DOMAINS = ['security', 'validators'];

    public function testEachPackagesTranslationsAreInItsOwnDomain(): void
    {
        $root = \dirname(__DIR__, 2);
        $checked = 0;
        $wrong = [];

        foreach ($this->glob($root.'/packages/*/composer.json') as $file) {
            /** @var array{name: string, extra?: array{quire?: array{id?: string}}} $composer */
            $composer = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
            $own = $this->domainOf($composer);

            foreach ($this->glob(\dirname($file).'/translations/*') as $catalogue) {
                ++$checked;
                $name = basename($catalogue);

                if (1 !== preg_match('/^(?<domain>[a-z0-9-]+)(?<icu>\+intl-icu)?\.(?<locale>[a-z]{2,3}(?:_[A-Z]{2})?)\.yaml$/', $name, $m)) {
                    $wrong[] = $composer['name'].': '.$name.' isn\'t <domain>[+intl-icu].<locale>.yaml';
                } elseif ($m['domain'] !== $own && !('quire' === $own && \in_array($m['domain'], self::SYMFONY_DOMAINS, true) && '' === $m['icu'])) {
                    $wrong[] = $composer['name'].': '.$name.' is in the domain "'.$m['domain'].'", not its own "'.$own.'"';
                }
            }
        }

        self::assertGreaterThan(10, $checked, 'The packages\' catalogues were found.');
        self::assertSame([], $wrong);
    }

    /**
     * An extension's actions in the activity log, and a group of settings named after it, are described from
     * its own texts. So the names core uses there are no extension's ID: an extension called "user" would
     * otherwise word core's "user.…" entries.
     */
    public function testTheNamesCoreDescribesInTheAdminAreNoExtensionsId(): void
    {
        $root = \dirname(__DIR__, 2);
        /** @var array<string, string> $admin */
        $admin = Yaml::parseFile($root.'/packages/admin/translations/quire-admin+intl-icu.en.yaml');
        $names = [];

        foreach (array_keys($admin) as $key) {
            if (1 === preg_match('/^audit\.([a-z0-9-]+)\./', $key, $m) || 1 === preg_match('/^settings\.group\.([a-z0-9-]+)$/', $key, $m)) {
                $names[$m[1]] = true;
            }
        }

        // And the actions core and the admin record, described or not
        foreach (new Finder()->files()->in([$root.'/packages/core/src', $root.'/packages/admin/src'])->name('*.php') as $file) {
            preg_match_all('/->record\(\s*\'([a-z0-9-]+)\./', $file->getContents(), $found);
            $names += array_fill_keys($found[1], true);
        }

        self::assertGreaterThan(10, \count($names), 'The names were found.');
        self::assertSame([], array_values(array_diff(array_keys($names), ManifestParser::CORE_NAMESPACES)));
    }

    public function testADomainIsAPackagesIdAndQuireIsCores(): void
    {
        self::assertSame('quire', $this->domainOf(['name' => 'quire/core']));
        self::assertSame('quire-privacy', $this->domainOf(['name' => 'quire/privacy']));
        self::assertSame('acme-reviews', $this->domainOf(['name' => 'acme/quire-reviews', 'extra' => ['quire' => ['id' => 'acme-reviews']]]));
    }

    /**
     * @return list<string>
     */
    private function glob(string $pattern): array
    {
        $found = glob($pattern);
        self::assertIsArray($found, $pattern.' could be read.');

        return $found;
    }

    /**
     * @param array{name: string, extra?: array{quire?: array{id?: string}}} $composer
     */
    private function domainOf(array $composer): string
    {
        return 'quire/core' === $composer['name'] ? 'quire' : ($composer['extra']['quire']['id'] ?? ManifestParser::idFromPackageName($composer['name']));
    }
}
