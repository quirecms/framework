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
        // The count includes Quire's built-in templates, which grow over time.
        self::assertMatchesRegularExpression('/The theme "quire-fixture-child" is fine: \d+ templates, every standard slot rendered\./', $output);
        self::assertStringContainsString('No template calls quire_head()', $output, 'Only a warning.');
    }

    public function testFindsTheMistakesOfATheme(): void
    {
        [$status, $output] = $this->lint('quire-fixture-broken');

        self::assertSame(1, $status);
        self::assertStringContainsString('The theme "quire-fixture-broken" has 4 problems.', $output);
        self::assertStringContainsString('quire-fixture-broken: templates/content/_card.html.twig uses the request (app.…), but cards are also shown alone, as previews.', preg_replace('/\s+/', ' ', $output) ?? '');
        self::assertStringContainsString('The theme has no screenshot', preg_replace('/\s+/', ' ', $output) ?? '', 'A warning: it still works.');
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

    public function testWarnsAboutHeadTagsQuireHeadPrintsToo(): void
    {
        [$status, $output] = $this->lint('quire-fixture-head-tags');

        self::assertSame(0, $status, 'Warnings only: the theme works.');
        self::assertStringContainsString('quire-fixture-head-tags: templates/layout.html.twig line 5: quire_seo() is now called quire_head(). The old name works until Quire 1.0.', $output);
        self::assertStringContainsString('quire-fixture-head-tags: templates/layout.html.twig line 6: prints <meta name="description"> itself, and quire_head() prints it too, so pages would have it twice.', $output);
        self::assertStringContainsString('quire-fixture-head-tags: templates/layout.html.twig line 7: prints <link rel="canonical"> itself', $output);
        self::assertStringNotContainsString('No template calls quire_head()', $output, 'quire_seo() still counts.');
    }

    public function testWarnsAboutCodeThePolicyBlocks(): void
    {
        [$status, $output] = $this->lint('quire-fixture-inline-code');

        self::assertSame(0, $status, 'Warnings only: pages still render.');
        $prefix = 'quire-fixture-inline-code: templates/layout.html.twig line ';
        self::assertStringContainsString($prefix.'6: an inline <script>, which the site\'s Content-Security-Policy blocks. Move the code to a script in the theme\'s assets (Extend\Assets).', $output);
        self::assertStringContainsString($prefix.'7: a <style> element, which the site\'s Content-Security-Policy blocks. Move the styles to the theme\'s stylesheet, with a class.', $output);
        self::assertStringContainsString($prefix.'14: a style attribute', $output);
        self::assertStringContainsString($prefix.'15: an event handler attribute (on…=)', $output);
        self::assertStringContainsString($prefix.'17: a javascript: link', $output);
        self::assertStringNotContainsString($prefix.'8:', $output, 'Structured data isn\'t code.');
        self::assertStringNotContainsString($prefix.'9:', $output, 'A script from a file is fine.');
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
