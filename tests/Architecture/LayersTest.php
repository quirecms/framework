<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parts of core that must stay independent of the rest (doc 20, the architect's review of 11 Nov 2026):
 * each may use only the core modules listed here, besides PHP, Symfony and PSR, so that core doesn't
 * grow more cycles between its modules.
 */
final class LayersTest extends TestCase
{
    private const string CORE = __DIR__.'/../../packages/core/src';

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function modules(): iterable
    {
        yield 'Head' => ['Head', ['Head', 'Extend', 'Http', 'Settings']];
    }

    /**
     * @param list<string> $allowed
     */
    #[DataProvider('modules')]
    public function testAModuleUsesOnlyTheModulesItMay(string $module, array $allowed): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::CORE.'/'.$module, \FilesystemIterator::SKIP_DOTS));
        $found = [];

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if ('php' !== $file->getExtension()) {
                continue;
            }

            preg_match_all('/^use\s+(?:function\s+|const\s+)?Quire\\\\(\w+)/m', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $used) {
                if (!\in_array($used, $allowed, true)) {
                    $found[] = \sprintf('%s uses Quire\%s', substr($file->getPathname(), \strlen(self::CORE) + 1), $used);
                }
            }
        }

        self::assertSame([], $found, \sprintf('Quire\%s may use only %s.', $module, implode(', ', $allowed)));
    }
}
