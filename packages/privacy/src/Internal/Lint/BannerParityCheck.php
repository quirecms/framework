<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Lint;

use Quire\Privacy\Category;
use Quire\Privacy\Service;
use Quire\Theme\ThemeCheckInterface;
use Quire\Theme\ThemeProblem;
use Twig\Environment;

/**
 * A theme's own banner (its override of quire/privacy's consent.html.twig), rendered with one kind of
 * service and with several, keeps what makes the choice fair: accepting and rejecting on the first
 * layer, once each, as buttons that look the same (the same classes: a stand-in, since styles by ID
 * or position can still tell them apart), with words, shown; a way to the details; and in the
 * settings, saving and rejecting. Quire's own banner is checked in a browser (consent.mjs).
 *
 * @internal
 */
final readonly class BannerParityCheck implements ThemeCheckInterface
{
    public const string TEMPLATE = 'extensions/quire_privacy/consent.html.twig';

    public function __construct(private Environment $twig)
    {
    }

    public function rendersTheme(): bool
    {
        return true;
    }

    public function check(string $theme, array $templates): array
    {
        $file = $templates[self::TEMPLATE] ?? null;

        if (null === $file || str_starts_with((string) realpath($file), (string) realpath(\dirname(__DIR__, 3).'/templates'))) {
            return [];
        }

        $problems = [];

        foreach (['single' => [Category::Statistics], 'several' => [Category::Statistics, Category::Marketing]] as $mode => $categories) {
            try {
                $html = $this->twig->createTemplate((string) file_get_contents($file), self::TEMPLATE)->render($this->view($mode, $categories));
            } catch (\Throwable $e) {
                $problems[] = ThemeProblem::error(\sprintf('%s doesn\'t render (%s): %s', self::TEMPLATE, $mode, $e->getMessage()));

                continue;
            }

            foreach ($this->rules($html) as $rule) {
                $problems[] = ThemeProblem::error(\sprintf('%s, with %s: %s', self::TEMPLATE, 'single' === $mode ? 'one kind of service' : 'several kinds of service', $rule));
            }
        }

        return $problems;
    }

    /**
     * What the rendered banner breaks of the rules, in words.
     *
     * @return list<string>
     */
    public function rules(string $html): array
    {
        $document = \Dom\HTMLDocument::createFromString('<!doctype html><body>'.$html, \LIBXML_NOERROR);
        $banner = $document->querySelector('[data-consent-banner]');

        if (!$banner instanceof \Dom\Element) {
            return ['there\'s no banner ([data-consent-banner]), so visitors can\'t choose.'];
        }

        $broken = [];
        $accept = $banner->querySelectorAll('[data-consent-accept]');
        $reject = $banner->querySelectorAll('[data-consent-reject]');

        if (1 !== $accept->length || 1 !== $reject->length) {
            $broken[] = \sprintf('the first layer has %d accept and %d reject buttons; it needs one of each, side by side, so rejecting is as easy as accepting.', $accept->length, $reject->length);
        } else {
            $yes = $accept->item(0);
            $no = $reject->item(0);
            \assert($yes instanceof \Dom\Element && $no instanceof \Dom\Element);

            if ('button' !== strtolower($yes->localName) || 'button' !== strtolower($no->localName)) {
                $broken[] = 'accepting and rejecting must both be buttons: one of them is a link or other element, which looks and works differently.';
            }

            if ($this->classes($yes) !== $this->classes($no)) {
                $broken[] = \sprintf('accepting ("%s") and rejecting ("%s") have different classes, so they can look different; give them the same (the check compares classes, a stand-in for how they look: don\'t style them apart by ID or position either).', implode(' ', $this->classes($yes)), implode(' ', $this->classes($no)));
            }

            foreach (['accepting' => $yes, 'rejecting' => $no] as $what => $button) {
                if ('' === trim((string) $button->textContent)) {
                    $broken[] = \sprintf('the button for %s has no words.', $what);
                }

                if ($this->hidden($button, $banner)) {
                    $broken[] = \sprintf('the button for %s is hidden on the first layer.', $what);
                }
            }
        }

        if (!$banner->querySelector('[data-consent-open]') instanceof \Dom\Element) {
            $broken[] = 'the first layer has no way to the details and choosing by service ([data-consent-open]).';
        }

        $dialog = $document->querySelector('[data-consent-dialog]');

        if (!$dialog instanceof \Dom\Element || !$dialog->querySelector('[data-consent-save]') instanceof \Dom\Element || !$dialog->querySelector('[data-consent-reject]') instanceof \Dom\Element) {
            $broken[] = 'the settings ([data-consent-dialog]) need a button to save the choice ([data-consent-save]) and one to reject ([data-consent-reject]).';
        }

        return $broken;
    }

    /**
     * @return list<string>
     */
    private function classes(\Dom\Element $element): array
    {
        $classes = preg_split('/\s+/', trim((string) $element->getAttribute('class')));
        $classes = array_values(array_filter(false === $classes ? [] : $classes, static fn (string $class): bool => '' !== $class));
        sort($classes);

        return $classes;
    }

    /** Hidden by itself or by something between it and the banner (which is hidden until the loader shows it) */
    private function hidden(\Dom\Element $element, \Dom\Element $banner): bool
    {
        for ($node = $element; $node instanceof \Dom\Element && $node !== $banner; $node = $node->parentElement) {
            if ($node->hasAttribute('hidden') || 'true' === $node->getAttribute('aria-hidden')) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the template is given on a page, with stand-in services.
     *
     * @param list<Category> $categories
     *
     * @return array<string, mixed>
     */
    private function view(string $mode, array $categories): array
    {
        $groups = array_map(static fn (Category $category): array => ['category' => $category, 'services' => [
            new Service('check-'.$category->value, 'A '.$category->value.' service', $category, 'A company', 'What it does.'),
        ]], $categories);

        return [
            'mode' => $mode,
            'category' => $categories[0],
            'layout' => 'bar',
            'reject' => 'reject',
            'test' => false,
            'privacy_page' => '/privacy',
            'categories' => $groups,
            'config' => [],
            'loader' => '/consent.js',
            'preview' => false,
        ];
    }
}
