<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * quire:theme:lint on the fixture themes in tests/Fixtures/themes.
 */
final class ThemeLintTest extends KernelTestCase
{
    public function testAThemeThatRendersEverySlotPasses(): void
    {
        [$status, $output] = $this->lint('quire-fixture-child');

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('The theme "quire-fixture-child" is fine: 5 templates, every standard slot rendered.', $output);
        self::assertStringContainsString('No template calls quire_seo()', $output, 'Only a warning.');
    }

    public function testFindsTheMistakesOfATheme(): void
    {
        [$status, $output] = $this->lint('quire-fixture-broken');

        self::assertSame(1, $status);
        self::assertStringContainsString('The theme "quire-fixture-broken" has 3 problems.', $output);
        self::assertStringContainsString('quire-fixture-broken: templates/content/single.html.twig: Unexpected "endblock" tag (expecting closing tag for the "if" tag defined near line 4)', $output);
        self::assertStringContainsString('quire-fixture-broken: templates/layout.html.twig: Unknown "acme_unknown_function" function', $output);
        self::assertStringContainsString('The theme doesn\'t render the standard slots ', $output, 'The layout doesn\'t compile, and the content template replaces the built-in one.');
    }

    public function testReportsMissingAssets(): void
    {
        $file = __DIR__.'/../Fixtures/themes/broken/templates/layout.html.twig';
        $original = (string) file_get_contents($file);
        file_put_contents($file, str_replace('{{ acme_unknown_function() }}', '', $original));

        try {
            [, $output] = $this->lint('quire-fixture-broken');
        } finally {
            file_put_contents($file, $original);
        }

        self::assertStringContainsString('quire-fixture-broken: templates/layout.html.twig line 6: quire_asset(\'theme/missing.png\') links to a file that doesn\'t exist.', $output);
    }

    public function testThemesThatCantBeUsedFail(): void
    {
        [$status, $output] = $this->lint('quire-fixture-orphan');

        self::assertSame(1, $status);
        self::assertStringContainsString('inherits from "quire-fixture-gone", which isn\'t installed', $output);
    }

    /**
     * @return array{int, string}
     */
    private function lint(string $id): array
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('quire:theme:lint'));
        $status = $tester->execute(['id' => $id]);

        return [$status, (string) preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }
}
