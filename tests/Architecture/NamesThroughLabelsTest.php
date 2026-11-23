<?php

declare(strict_types=1);

namespace Quire\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The names people read for content types, roles and settings are printed through Quire\Translation\Labels
 * (or its Twig functions, quire_type_label() and the others), never as declared: a declared label is
 * English, and its owner's domain isn't its owner's ID for core ("core" translates in "quire"). Composing
 * a sentence around a type's name ("New {type}") can't be translated either: a type words its phrases.
 */
final class NamesThroughLabelsTest extends TestCase
{
    /** Code where a declared name is English on purpose: the console, and messages for developers */
    private const array ENGLISH = [
        'packages/admin/src/Controller/ContentChangesController.php',
        'packages/admin/src/Controller/ContentEditController.php',
        'packages/admin/src/Controller/ContentListController.php',
    ];

    public function testNoTemplatePrintsADeclaredName(): void
    {
        $found = [];

        foreach (new Finder()->files()->in(\dirname(__DIR__, 2).'/packages/*/templates')->name('*.twig') as $file) {
            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (1 === preg_match('/\btype\.(?:label|pluralLabel)\b|\|trans\(\{\}, [\w.]*owner\)|\{type\}/', $line)) {
                    $found[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        self::assertSame([], $found);
    }

    public function testNoCodeShowsADeclaredNameToPeople(): void
    {
        $root = \dirname(__DIR__, 2);
        $found = [];

        foreach (new Finder()->files()->in([$root.'/packages/admin/src', $root.'/packages/core/src/Theme'])->name('*.php') as $file) {
            $path = 'packages/'.substr($file->getPathname(), \strlen($root.'/packages/'));

            if (\in_array($path, self::ENGLISH, true)) {
                continue;
            }

            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (1 === preg_match('/->pluralLabel\b|trans\([^)]*->owner\)|permissions->roles\(\)\[|\$type->label\b/', $line) && !str_contains($line, 'Labels::domainOf(')) {
                    $found[] = $path.':'.($i + 1);
                }
            }
        }

        self::assertSame([], $found);
    }
}
