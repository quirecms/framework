<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Quire UI's colour tokens: every pair of text and background the components use reads at WCAG AA
 * (4.5:1), in light and in dark. Axe checks pages; this checks the tokens themselves, also for
 * combinations no page happens to show.
 */
final class DesignTokensTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pairs(): iterable
    {
        foreach ([
            ['ink', 'paper'], ['ink', 'surface'], ['ink', 'raised'],
            ['muted', 'paper'], ['muted', 'surface'],
            ['accent', 'paper'], ['accent', 'surface'], ['accent-ink', 'accent'], ['accent-strong', 'accent-soft'],
            ['danger', 'surface'], ['danger', 'danger-soft'],
            ['warn', 'warn-soft'], ['warn', 'surface'],
            ['review', 'review-soft'], ['review', 'surface'],
            ['draft-ink', 'surface'], ['scheduled-ink', 'surface'], ['live', 'surface'],
        ] as [$text, $background]) {
            yield $text.' on '.$background => [$text, $background];
        }
    }

    #[DataProvider('pairs')]
    public function testTextIsReadableInLightAndDark(string $text, string $background): void
    {
        $tokens = $this->tokens();

        foreach (['light' => 0, 'dark' => 1] as $mode => $index) {
            $ratio = $this->contrast($tokens[$text][$index], $tokens[$background][$index]);
            self::assertGreaterThanOrEqual(4.5, $ratio, \sprintf('%s: --q-%s (%s) on --q-%s (%s) is %.2f:1.', $mode, $text, $tokens[$text][$index], $background, $tokens[$background][$index], $ratio));
        }
    }

    /**
     * The first declaration of each "--q-x: light-dark(#light, #dark)" in quire-ui.css (the default set).
     *
     * @return array<string, array{string, string}>
     */
    private function tokens(): array
    {
        $css = (string) file_get_contents(__DIR__.'/../assets/quire-ui.css');
        preg_match_all('/--q-([a-z-]+):\s*light-dark\((#[0-9a-f]{6}),\s*(#[0-9a-f]{6})\)/i', $css, $matches, \PREG_SET_ORDER);
        $tokens = [];

        foreach ($matches as [, $name, $light, $dark]) {
            $tokens[$name] ??= [$light, $dark];
        }

        return $tokens;
    }

    private function contrast(string $a, string $b): float
    {
        [$high, $low] = [max($this->luminance($a), $this->luminance($b)), min($this->luminance($a), $this->luminance($b))];

        return ($high + 0.05) / ($low + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
