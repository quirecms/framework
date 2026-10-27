<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Testing\TestInstallation;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Every message of Quire's packages in every language they ship: the same keys, and the same values
 * to fill in ("{title}", "{count, plural, …}"), so no screen falls back to English or loses a name.
 */
final class TranslationsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function languages(): iterable
    {
        $files = Finder::create()->files()->in(TestInstallation::projectDir().'/packages/*/translations')->name('*.en.yaml');

        foreach ($files as $english) {
            $files = glob(str_replace('.en.yaml', '.*.yaml', $english->getPathname()));

            foreach (false === $files ? [] : $files as $other) {
                if ($other !== $english->getPathname()) {
                    yield basename(\dirname($english->getPath())).'/'.basename($other) => [$english->getPathname(), $other];
                }
            }
        }
    }

    #[DataProvider('languages')]
    public function testTheSameMessagesWithTheSameValuesToFillIn(string $english, string $other): void
    {
        $en = Yaml::parseFile($english);
        $translated = Yaml::parseFile($other);
        self::assertIsArray($en);
        self::assertIsArray($translated);

        self::assertSame([], array_keys(array_diff_key($en, $translated)), 'Untranslated.');
        self::assertSame([], array_keys(array_diff_key($translated, $en)), 'Not in English: left over.');

        $different = [];

        foreach ($en as $key => $message) {
            if ($this->arguments(\is_string($message) ? $message : '') !== $this->arguments(\is_string($translated[$key]) ? $translated[$key] : '')) {
                $different[] = $key;
            }
        }

        self::assertSame([], $different, 'Translated with other values to fill in.');
    }

    /**
     * @return list<string> The names of the values a message fills in, e.g. ["count", "title"]
     */
    private function arguments(string $message): array
    {
        preg_match_all('/\{\s*([A-Za-z_][\w.]*)\s*[,}]/', $message, $matches, \PREG_OFFSET_CAPTURE);
        $names = [];

        foreach ($matches[1] as $index => [$name]) {
            $before = substr($message, 0, $matches[0][$index][1]);
            // A plural's or select's branch ("one {Undone.}") is text, not a value.
            $branch = 1 === preg_match('/,\s*(?:plural|select|selectordinal)\s*,/', $before) && 1 === preg_match('/(?:^|[\s}])(?:zero|one|two|few|many|other|=\d+)\s*$/', $before);

            if (!$branch) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
