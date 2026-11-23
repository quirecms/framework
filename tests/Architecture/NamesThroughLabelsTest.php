<?php

declare(strict_types=1);

namespace Quire\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * The names people read for content types, roles, abilities and settings are printed through
 * Quire\Translation\Labels (or its Twig functions, quire_type_label() and the others), never as declared: a
 * declared label is English, and its owner's domain isn't its owner's ID for core ("core" translates in
 * "quire"). Composing a sentence around a type's name ("New {type}") can't be translated either: a type
 * words its phrases.
 *
 * So every translation names its domain as a literal (a constant, or a template's `domain`, set to one), or
 * through Labels::domainOf(): a domain worked out any other way (an owner, a group) is how a declared name
 * ends up in the wrong texts, whatever it's spelled.
 */
final class NamesThroughLabelsTest extends TestCase
{
    /** Code where a declared name is English on purpose: the console, and messages for developers */
    private const array ENGLISH = [
        'packages/admin/src/Controller/ContentChangesController.php',
        'packages/admin/src/Controller/ContentEditController.php',
        'packages/admin/src/Controller/ContentListController.php',
    ];

    /** Domains worked out on purpose, by file: the expression each may use */
    private const array DOMAINS = [
        // Labels and quire_trans are what work domains out
        'packages/core/src/Translation/Labels.php' => ['$domain'],
        'packages/core/src/Theme/Twig/TranslationRuntime.php' => ['$domain'],
        // An installed extension's own texts, by its ID: its group's name, its actions' descriptions
        'packages/admin/src/Site/SettingsForm.php' => ['$this->installed->has($group) ? $group : \'quire-admin\''],
        'packages/admin/src/Audit/AuditEntries.php' => ['$domain'],
    ];

    /** A Twig translation whose domain is neither a literal, the template's `domain`, nor quire_domain(owner) */
    private const string TWIG_DOMAIN = '/\|(?:quire_)?trans\((?:[^()]|\([^()]*\))*,\s*+(?![\'"]|domain\s*\)|quire_domain\()[^,()]+\)/';

    public function testNoTemplatePrintsADeclaredName(): void
    {
        $found = [];

        foreach (new Finder()->files()->in(\dirname(__DIR__, 2).'/packages/*/templates')->name('*.twig') as $file) {
            $twig = $file->getContents();

            // The whole file: a filter's arguments may span lines
            foreach (['' => '/\btype\.(?:label|pluralLabel)\b|\{type\}/', ' (domain)' => self::TWIG_DOMAIN, ' (domain set to no literal)' => '/\bset domain = (?![\'"][\w.-]+[\'"]\s*-?%\})/'] as $what => $pattern) {
                preg_match_all($pattern, $twig, $matches, \PREG_OFFSET_CAPTURE);

                foreach ($matches[0] as [, $offset]) {
                    $found[] = $file->getRelativePathname().':'.(substr_count($twig, "\n", 0, $offset) + 1).$what;
                }
            }
        }

        self::assertSame([], $found);
    }

    public function testNoCodeShowsADeclaredNameToPeople(): void
    {
        $root = \dirname(__DIR__, 2);
        $found = [];

        foreach (new Finder()->files()->in([$root.'/packages/admin/src', $root.'/packages/core/src/Theme', $root.'/packages/core/src/Http'])->name('*.php') as $file) {
            $path = 'packages/'.substr($file->getPathname(), \strlen($root.'/packages/'));

            if (\in_array($path, self::ENGLISH, true)) {
                continue;
            }

            foreach (explode("\n", $file->getContents()) as $i => $line) {
                if (1 === preg_match('/->pluralLabel\b|permissions->roles\(\)\[|permissions->abilities\(\)\[[^\]]+\]\[\'label\'\]|\$type->label\b/', $line)) {
                    $found[] = $path.':'.($i + 1);
                }
            }
        }

        self::assertSame([], $found);
    }

    public function testEveryTranslationNamesItsDomain(): void
    {
        $root = \dirname(__DIR__, 2);
        $found = [];

        foreach (new Finder()->files()->in($root.'/packages/*/src')->name('*.php') as $file) {
            $path = 'packages/'.substr($file->getPathname(), \strlen($root.'/packages/'));

            foreach ($this->domains($file->getContents()) as [$line, $domain]) {
                if (1 !== preg_match('/^(?:\'[^\']*\'|"[^"$]*"|(?:self|static|[A-Z]\w*)::[A-Z][A-Z0-9_]*|(?:self|Labels)::domainOf\(.*\))$/s', $domain) && !\in_array($domain, self::DOMAINS[$path] ?? [], true)) {
                    $found[] = $path.':'.$line.' '.$domain;
                }
            }
        }

        self::assertSame([], $found);
    }

    public function testTheScanReadsEachSpelling(): void
    {
        self::assertSame([1, 1, 0, 0, 0, 0], array_map(static fn (string $twig): int => (int) preg_match(self::TWIG_DOMAIN, $twig), [
            '{{ type.label|trans({}, type.owner) }}',
            "{{ 'x'|trans({n: f(1)}, ability['owner']) }}",
            "{{ 'x'|trans({n: f(1)}, 'quire') }}",
            "{{ 'x'|quire_trans({}, domain) }}",
            '{{ service.purpose|quire_trans({}, quire_domain(service.owner)) }}',
            "{{ 'x'|trans }}",
        ]));
        self::assertMatchesRegularExpression(self::TWIG_DOMAIN, "{{ 'x'|trans({\n    n: 1,\n}, type.owner) }}", 'Across lines.');

        $code = '<?php $t->trans(\'a\', [], $ability[\'owner\']); $t->trans(\'b\', [\'x\' => f(1, 2)], $type->owner); $t->trans(\'c\', [], \'quire\'); $t->trans(\'d\'); $t->trans(\'e\', domain: $owner); $t->trans(id: \'f\', domain: \'quire\', parameters: []); $t->trans(\'g\', [], self::DOMAIN);';

        self::assertSame([[1, '$ability[\'owner\']'], [1, '$type->owner'], [1, '\'quire\''], [1, '\'messages\''], [1, '$owner'], [1, '\'quire\''], [1, 'self::DOMAIN']], $this->domains($code));
    }

    /**
     * The domain of each ->trans() call, as written ("'messages'" when left out).
     *
     * @return list<array{int, string}>
     */
    private function domains(string $code): array
    {
        $tokens = \PhpToken::tokenize($code);
        $count = \count($tokens);
        $domains = [];

        for ($i = 0; $i < $count; ++$i) {
            if (!$tokens[$i]->is(\T_STRING) || 'trans' !== $tokens[$i]->text) {
                continue;
            }

            $before = $i - 1;

            while ($before >= 0 && $tokens[$before]->isIgnorable()) {
                --$before;
            }

            $open = $i + 1;

            while ($open < $count && $tokens[$open]->isIgnorable()) {
                ++$open;
            }

            if ($before < 0 || !$tokens[$before]->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]) || $open >= $count || '(' !== $tokens[$open]->text) {
                continue;
            }

            $arguments = [''];
            $depth = 0;

            for ($k = $open + 1; $k < $count; ++$k) {
                $text = $tokens[$k]->text;

                if (\in_array($text, [')', ']', '}'], true) && 0 === $depth--) {
                    break;
                }

                if (\in_array($text, ['(', '[', '{', '${'], true) || $tokens[$k]->is(\T_CURLY_OPEN)) {
                    ++$depth;
                }

                if (',' === $text && 0 === $depth) {
                    $arguments[] = '';

                    continue;
                }

                $arguments[\count($arguments) - 1] .= $text;
            }

            // By name ("domain: …") or third
            [$positional, $named] = [[], []];

            foreach ($arguments as $argument) {
                if (1 === preg_match('/^\s*(\w+)\s*:(?!:)(.*)$/s', $argument, $match)) {
                    $named[$match[1]] = trim($match[2]);
                } else {
                    $positional[] = trim($argument);
                }
            }

            $domains[] = [$tokens[$i]->line, $named['domain'] ?? $positional[2] ?? '\'messages\''];
        }

        return $domains;
    }
}
