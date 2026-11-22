<?php

declare(strict_types=1);

namespace Quire\ThemeDefault\Tests;

use PHPUnit\Framework\TestCase;

/**
 * What docs/themes.md promises child themes ("What a child theme may rely on"): every custom property it
 * lists is defined by the default theme's stylesheet, every class it lists has rules there, and every
 * block of the layout it lists exists. That list is the theme's interface for children: changing the
 * stylesheet can't drop one of them without this failing.
 */
final class ChildThemeContractTest extends TestCase
{
    public function testWhatTheDocsPromiseChildThemesIsThere(): void
    {
        $root = \dirname(__DIR__, 3);
        $docs = (string) file_get_contents($root.'/docs/themes.md');
        self::assertSame(1, preg_match('/### What a child theme may rely on\n(.*?)\n## /s', $docs, $section), 'The docs have the section.');

        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(__DIR__.'/../assets/style.css'));
        $layout = (string) file_get_contents(__DIR__.'/../templates/layout.html.twig');

        preg_match('/\*\*Custom properties:\*\*(.*)/', $section[1], $line);
        preg_match_all('/`(--[a-z0-9-]+)`/', $line[1] ?? '', $properties);
        self::assertNotEmpty($properties[1]);
        foreach ($properties[1] as $property) {
            self::assertMatchesRegularExpression('/(?<![\w-])'.preg_quote($property, '/').'\s*:/', $css, $property.' is defined.');
        }

        preg_match('/\*\*Classes:\*\*(.*)/', $section[1], $line);
        preg_match_all('/`\.([a-z][a-z0-9_-]*)`/', $line[1] ?? '', $classes);
        self::assertNotEmpty($classes[1]);
        foreach ($classes[1] as $class) {
            self::assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])[^{}]*\{/', $css, '.'.$class.' has rules.');
        }

        preg_match('/\*\*Blocks of the layout:\*\*(.*)/', $section[1], $line);
        preg_match_all('/`([a-z_]+)`/', $line[1] ?? '', $blocks);
        self::assertNotEmpty($blocks[1]);
        foreach ($blocks[1] as $block) {
            self::assertStringContainsString('{% block '.$block.' %}', $layout, 'The layout has the block '.$block.'.');
        }
    }
}
