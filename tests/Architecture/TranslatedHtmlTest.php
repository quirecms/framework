<?php

declare(strict_types=1);

namespace Quire\Tests\Architecture;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Node\Expression\Variable\AssignContextVariable;
use Twig\Node\Expression\Variable\ContextVariable;
use Twig\Node\Node;
use Twig\Node\SetNode;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * ARC-0063: no HTML template prints a translation unescaped. A translation is text someone else
 * wrote: `|trans|raw` would make it markup, and script, in every page that shows it. HTML in the
 * parameters goes through `quire_trans_html`, which escapes the text and takes only the code's markup.
 *
 * Read from Twig's own parse of every HTML template (packages, the project's templates/ and the
 * skeleton's), so a print over several lines or a translation kept in a variable (`{% set t = …|trans %}`
 * then `{{ t|raw }}`) is found too. Plain-text templates (e-mails' .txt.twig) aren't HTML.
 */
final class TranslatedHtmlTest extends KernelTestCase
{
    public function testNoHtmlTemplatePrintsATranslationRaw(): void
    {
        $twig = $this->reader();
        $root = \dirname(__DIR__, 2);
        $roots = array_values(array_filter([$root.'/packages', $root.'/templates', $root.'/skeleton'], is_dir(...)));
        $found = [];

        foreach (new Finder()->files()->in($roots)->name('*.html.twig')->notPath('vendor') as $template) {
            foreach ($this->found($twig, $template->getContents(), $template->getRelativePathname()) as $line) {
                $found[] = $template->getRelativePathname().':'.$line;
            }
        }

        self::assertSame([], $found, 'Translations printed raw: use quire_trans_html, with the HTML as a Markup parameter (docs/themes.md, "Languages").');
    }

    public function testItFindsEveryWayOfPrintingOneRawButEncoded(): void
    {
        $twig = $this->reader();

        self::assertSame([1], $this->found($twig, "{{ 'Hi'|trans|raw }}"));
        self::assertSame([3], $this->found($twig, "{{ 'Hi {x}'\n|trans({x: 1}, 'd')\n|raw }}"), 'Over several lines (Twig counts the line of its raw).');
        self::assertSame([2], $this->found($twig, "{% set t = 'Hi'|trans %}\n{{ t|raw }}"), 'Kept in a variable.');
        self::assertSame([1], $this->found($twig, "{{ ('Hi'|trans ~ '!')|raw }}"));
        self::assertSame([], $this->found($twig, "{{ {m: 'Hi'|trans}|json_encode|raw }}"), 'Encoded as JSON first: not markup.');
        self::assertSame([], $this->found($twig, "{{ 'Hi'|trans }} {{ html|raw }}"));
    }

    private function reader(): Environment
    {
        /** @var Environment $kernelTwig */
        $kernelTwig = self::getContainer()->get('twig');
        // The site's extensions (filters, tags), and any other a package's template uses taken as
        // unknown: this reads templates, it never renders them
        $twig = new Environment(new ArrayLoader());
        foreach ($kernelTwig->getExtensions() as $extension) {
            if (!$twig->hasExtension($extension::class)) {
                $twig->addExtension($extension);
            }
        }
        $twig->registerUndefinedFilterCallback(static fn (string $name): TwigFilter => new TwigFilter($name, static fn (): null => null));
        $twig->registerUndefinedFunctionCallback(static fn (string $name): TwigFunction => new TwigFunction($name, static fn (): null => null));
        $twig->registerUndefinedTestCallback(static fn (string $name): TwigTest => new TwigTest($name, static fn (): bool => false));

        return $twig;
    }

    /**
     * @return list<int> The lines that print a translation raw
     */
    private function found(Environment $twig, string $code, string $name = 'test.html.twig'): array
    {
        $tree = $twig->parse($twig->tokenize(new Source($code, $name)));
        $translated = [];
        $found = [];
        $this->walk($tree, $translated, $found);

        return $found;
    }

    /**
     * @param array<string, true> $translated Variables set from a translation
     * @param list<int>           $found
     */
    private function walk(Node $node, array &$translated, array &$found): void
    {
        if ($node instanceof SetNode && $node->hasNode('names') && $node->hasNode('values') && $this->has($node->getNode('values'), 'trans')) {
            foreach ($node->getNode('names') as $name) {
                if ($name instanceof AssignContextVariable) {
                    $translated[$this->variableName($name)] = true;
                }
            }
        }

        if ('raw' === $this->filter($node) && $node->hasNode('node')) {
            $inner = $node->getNode('node');

            if ($this->has($inner, 'trans') || ($inner instanceof ContextVariable && isset($translated[$this->variableName($inner)]))) {
                $found[] = $node->getTemplateLine();
            }
        }

        foreach ($node as $child) {
            $this->walk($child, $translated, $found);
        }
    }

    /**
     * Filters that make their input safe for HTML whatever it holds, so a translation under them
     * isn't markup: `json_encode` (in a JSON <script>, with JSON_HEX_TAG), `escape`/`e`, `url_encode`.
     */
    private const array ENCODERS = ['json_encode', 'escape', 'e', 'url_encode', 'striptags'];

    /** Whether a filter of that name is applied in the expression, other than under an encoder */
    private function has(Node $node, string $filter): bool
    {
        $name = $this->filter($node);

        if ($name === $filter) {
            return true;
        }
        if (\in_array($name, self::ENCODERS, true)) {
            return false;
        }

        foreach ($node as $child) {
            if ($this->has($child, $filter)) {
                return true;
            }
        }

        return false;
    }

    private function variableName(Node $variable): string
    {
        $name = $variable->getAttribute('name');

        return \is_string($name) ? $name : '';
    }

    private function filter(Node $node): ?string
    {
        if ($node->hasAttribute('twig_callable')) {
            $callable = $node->getAttribute('twig_callable');

            return $callable instanceof TwigFilter ? $callable->getName() : null;
        }

        return null;
    }
}
