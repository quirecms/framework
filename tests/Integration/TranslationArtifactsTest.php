<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Quire\Testing\TestInstallation;
use Quire\Testing\TestKernel;
use Quire\Translation\Internal\ArtifactLoader;
use Quire\Translation\Internal\TranslationArtifactsPass;
use Quire\Translation\TranslationArtifacts;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Language artifacts (tests/Fixtures/translation-artifacts): data loaded file by file, over the package's
 * own texts and under the themes' and the site's; any that doesn't fit is left out, with why.
 */
final class TranslationArtifactsTest extends TestCase
{
    private const string FIXTURES = '/tests/Fixtures/translation-artifacts';

    private const string MARKER = 'quire-artifact-php-ran';

    protected function setUp(): void
    {
        @unlink(sys_get_temp_dir().'/'.self::MARKER);
    }

    public function testEachArtifactIsLoadedOrLeftOutWithWhy(): void
    {
        $dir = TestInstallation::projectDir().self::FIXTURES;
        /** @var array{packages: list<array<mixed>>} $installed */
        $installed = json_decode((string) file_get_contents($dir.'/installed.json'), true, flags: \JSON_THROW_ON_ERROR);
        $statuses = [];

        foreach (new TranslationArtifacts()->read($installed['packages'], $dir) as $status) {
            $statuses[$status->name] = $status->loaded ? 'loaded' : (string) $status->reason;
        }

        self::assertSame([
            'acme/hub-shop-mk' => 'loaded',
            'acme/hub-shop-de' => 'It\'s for acme/hub-shop 0.4; 0.5.2 is installed.',
            'acme/hub-shop-fr' => 'acme/hub-shop has no domain "quire-admin".',
            'acme/hub-shop-it' => 'It holds "translations/acme-hub-shop.it.php", which isn\'t a text of its own: only acme-hub-shop+intl-icu.it.yaml.',
            'acme/hub-shop-es' => 'It carries code ("autoload"); an artifact is texts only.',
            'acme/hub-shop-pl' => $statuses['acme/hub-shop-pl'],
            'acme/privacy-mk' => 'quire/privacy ships its own translations ("delivery": "bundled").',
            'acme/hub-shop-sv' => 'other/hub-shop-sv translates acme/hub-shop into "sv" too; with two, neither is loaded.',
            'other/hub-shop-sv' => 'acme/hub-shop-sv translates acme/hub-shop into "sv" too; with two, neither is loaded.',
            'acme/hub-shop-de-de' => 'Its manifest isn\'t one: "locale" is "de-DE", not a canonical language code such as "mk" or "pt_BR".',
        ], $statuses);
        self::assertStringStartsWith('"acme-hub-shop+intl-icu.pl.yaml" isn\'t plain texts', $statuses['acme/hub-shop-pl'], 'A PHP constant is no text.');
    }

    public function testAnArtifactRanksOverThePackageAndUnderEachTheme(): void
    {
        foreach (['quire-fixture-child' => 'child', 'quire-fixture-base' => 'base'] as $theme => $childTheme) {
            $translator = $this->translator($theme);
            $mk = static fn (string $text): string => $translator->trans($text, [], 'acme-hub-shop', 'mk');

            self::assertSame('bundled', $mk('Only bundled'), 'The package\'s own, where the artifact has none.');
            self::assertSame('artifact', $mk('Artifact'), 'The artifact over the package.');
            self::assertSame('base', $mk('Base theme'), $theme.': the base theme over the artifact.');
            self::assertSame($childTheme, $mk('Child theme'), $theme.': the active theme over its parent and the artifact.');
            self::assertSame('Artifact', $translator->trans('Artifact', [], 'acme-hub-shop', 'pl'), 'A PHP constant isn\'t loaded: English.');
            self::assertSame('Artifact', $translator->trans('Artifact', [], 'acme-hub-shop', 'sv'), 'Two artifacts for one language: neither.');
            self::assertSame('Artifact', $translator->trans('Artifact', [], 'acme-hub-shop', 'it'), 'An artifact with a PHP file: none of it.');
        }

        self::assertFileDoesNotExist(sys_get_temp_dir().'/'.self::MARKER, 'An artifact\'s PHP file never ran.');
    }

    public function testAnArtifactsFileIsReadAsPlainTextsWhenItsLoadedToo(): void
    {
        $dir = TestInstallation::projectDir().self::FIXTURES.'/artifacts';
        $inner = new class implements LoaderInterface {
            public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
            {
                return new MessageCatalogue($locale, [$domain => ['by' => 'Symfony']]);
            }
        };
        $changed = $dir.'/pl-const/translations/acme-hub-shop+intl-icu.pl.yaml';
        $loader = new ArtifactLoader($inner, [$dir.'/mk/translations/acme-hub-shop+intl-icu.mk.yaml', $changed]);

        self::assertSame('artifact', $loader->load($dir.'/mk/translations/acme-hub-shop+intl-icu.mk.yaml', 'mk', 'acme-hub-shop+intl-icu')->get('Artifact', 'acme-hub-shop+intl-icu'));
        self::assertSame([], $loader->load($changed, 'pl', 'acme-hub-shop+intl-icu')->all(), 'One that stopped being plain texts since it was checked: left out.');
        self::assertSame('Symfony', $loader->load('/elsewhere/texts.mk.yaml', 'mk', 'other')->get('by', 'other'), 'Any other file: Symfony\'s loader.');
    }

    public function testTheFilesGoBeforeTheFirstOfAThemeOrTheSite(): void
    {
        $same = static fn (string $path): string => $path;
        $files = ['/app/packages/core/translations/quire.mk.yaml', '/app/ext/translations/ext.mk.yaml', '/themes/base/translations/a.mk.yaml', '/themes/child/translations/a.mk.yaml', '/app/translations/a.mk.yaml'];

        foreach ([[['/themes/child/translations', '/themes/base/translations', '/app/translations'], 2], [['/themes/child/translations', '/app/translations'], 3], [['/app/translations'], 4], [[], 5]] as [$overrides, $at]) {
            $expected = $files;
            array_splice($expected, $at, 0, ['/artifact/x.mk.yaml']);
            self::assertSame($expected, TranslationArtifactsPass::insert($files, ['/artifact/x.mk.yaml'], $overrides, $same));
        }

        self::assertSame(['/app/translations-old/a.mk.yaml', '/artifact/x.mk.yaml'], TranslationArtifactsPass::insert(['/app/translations-old/a.mk.yaml'], ['/artifact/x.mk.yaml'], ['/app/translations'], $same), 'A directory, not a prefix of a name.');
    }

    public function testTheSiteKnowsWhatBecameOfEach(): void
    {
        $kernel = $this->kernel('quire-fixture-child');
        /** @var list<array{name: string, status: string}> $artifacts */
        $artifacts = $kernel->getContainer()->getParameter(TranslationArtifactsPass::ARTIFACTS);

        self::assertSame(['acme/hub-shop-mk'], array_values(array_map(static fn (array $a): string => $a['name'], array_filter($artifacts, static fn (array $a): bool => 'loaded' === $a['status']))));
        self::assertCount(10, $artifacts);
    }

    private function translator(string $theme): TranslatorInterface
    {
        return $this->kernel($theme)->getContainer()->get('translator');
    }

    private function kernel(string $theme): TestKernel
    {
        $kernel = new TestKernel(['quire-fixture-hello', 'acme-hub-shop'], TestInstallation::projectDir().self::FIXTURES.'/installed.json', theme: $theme);
        $kernel->boot();

        return $kernel;
    }
}
