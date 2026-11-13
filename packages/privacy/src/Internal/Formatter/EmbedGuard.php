<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Formatter;

use Quire\Csp\Policy;
use Quire\Formatter\EmbedGuardInterface;
use Quire\Privacy\Internal\Theme\Banner;
use Quire\Privacy\Service;
use Quire\Privacy\Services;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Twig\Environment;

/**
 * Holds what a trusted shortcode prints, when it embeds from a service visitors are asked about (a
 * video, a map), until the visitor allows it: in its place, one placeholder that says who it's from and
 * what loading it does, with the output inside as data (JSON), for the consent loader to put back (doc 20,
 * condition C-B).
 *
 * The site's policy allows a service's hosts for every visitor, so pages stay cacheable; for embeds,
 * this is what makes them wait. It fails closed: markup it can't read, a URL whose host it can't tell,
 * a frame's own document (srcdoc), <noscript> (which browsers and this parser read differently) or
 * styles that may load something (url(), @import) are held too.
 * Hosts of necessary services, the site's own URLs and hosts no service declares (which the policy
 * blocks anyway) are left as they are.
 *
 * @internal
 */
final readonly class EmbedGuard implements EmbedGuardInterface
{
    /** What can load something from another site */
    private const array ELEMENTS = ['iframe', 'frame', 'script', 'object', 'embed', 'video', 'audio', 'picture', 'source', 'track', 'img', 'image', 'input', 'link', 'style', 'noscript'];

    /**
     * @param \Closure(): Environment $twig Later: Twig renders content, and content is formatted here
     */
    public function __construct(
        private Services $services,
        private Banner $banner,
        #[AutowireServiceClosure('twig')]
        private \Closure $twig,
    ) {
    }

    public function guard(string $html): string
    {
        $pattern = '~<\s*(?:'.implode('|', self::ELEMENTS).')\b|\ssrcdoc\s*=~i';

        if (1 !== preg_match($pattern, $html) || [] === $this->services->askedAbout()) {
            return $html;
        }

        try {
            $document = \Dom\HTMLDocument::createFromString('<!doctype html><html><head></head><body>'.$html.'</body></html>', \LIBXML_NOERROR);
        } catch (\Throwable) {
            return $this->placeholder(null, null, $html);
        }

        $body = $document->body;

        if (!$body instanceof \Dom\HTMLElement) {
            return $this->placeholder(null, null, $html);
        }

        // One placeholder for what the shortcode printed: its parts belong together (a frame and its
        // script). It's from one service, or, from several or one it can't tell, loaded once only.
        $services = [];
        $unknown = false;
        $first = null;

        foreach ($body->querySelectorAll(implode(', ', [...self::ELEMENTS, '[srcdoc]'])) as $element) {
            $verdict = $this->verdict($element);

            if (false === $verdict) {
                continue;
            }

            $first ??= $this->firstUrl($element);

            if (!$verdict instanceof Service) {
                $unknown = true;
            } else {
                $services[$verdict->id] = $verdict;
            }
        }

        if (!$unknown && [] === $services) {
            return $html;
        }

        // The output as it was printed, never as this parser wrote it back: browsers read some markup
        // otherwise (e.g. <noscript>, with scripting on), and a rewritten attribute could end what holds
        // it. The placeholder carries it as JSON, for the loader to read with the browser's own parser.
        return $this->placeholder(!$unknown && 1 === \count($services) ? array_first($services) : null, $first, $html);
    }

    /**
     * False to leave the element as it is; the service it's from, or null for one it can't tell.
     */
    private function verdict(\Dom\Element $element): Service|false|null
    {
        $name = strtolower($element->localName);

        // A frame's own document, and what browsers read as text but this parser as markup (or the
        // other way round, when its attributes hold markup): what they'd load can't be told here.
        if ($element->hasAttribute('srcdoc') || 'noscript' === $name) {
            return null;
        }

        if ('style' === $name) {
            return 1 === preg_match('/url\s*\(|@import/i', (string) $element->textContent) ? null : false;
        }

        foreach ($this->urls($element) as $url) {
            $absolute = $this->absolute($url);

            if (false === $absolute) {
                continue;
            }

            if (null === $absolute) {
                return null;
            }

            $service = $this->service($absolute);

            if ($service instanceof Service && $service->category->needsConsent()) {
                return $service;
            }
        }

        return false;
    }

    /**
     * Every URL the element, and what it contains, may load.
     *
     * @return list<string>
     */
    private function urls(\Dom\Element $element): array
    {
        $urls = [];
        $elements = [$element, ...$element->querySelectorAll('*')];

        foreach ($elements as $each) {
            foreach (['src', 'href', 'poster', 'data', 'background', 'xlink:href', 'action', 'formaction'] as $attribute) {
                $value = $each->getAttribute($attribute);

                if (null !== $value) {
                    $urls[] = $value;
                }
            }

            foreach (['srcset', 'imagesrcset'] as $attribute) {
                $value = $each->getAttribute($attribute);

                foreach (null === $value ? [] : explode(',', $value) as $candidate) {
                    $url = preg_split('/\s+/', trim($candidate))[0] ?? '';

                    if ('' !== $url) {
                        $urls[] = $url;
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * The URL to load, absolute; false for the site's own (relative) or one that loads nothing from
     * another site; null for one whose host can't be told.
     */
    private function absolute(string $url): string|false|null
    {
        // As browsers read it: without leading and trailing spaces and control characters, and without
        // tabs and line breaks anywhere ("ht\ttps:" is "https:").
        $url = trim((string) preg_replace('/[\t\n\r]+/', '', $url), "\x00..\x20");

        if ('' === $url || str_starts_with($url, '#')) {
            return false;
        }

        if (str_starts_with($url, '//') || str_starts_with($url, '\\\\') || str_starts_with($url, '/\\') || str_starts_with($url, '\\/')) {
            $url = 'https:'.str_replace('\\', '/', $url);
        }

        if (1 !== preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $match)) {
            // A path on the site itself.
            return false;
        }

        $scheme = strtolower($match[1]);

        if (\in_array($scheme, ['data', 'blob', 'about', 'javascript', 'mailto', 'tel'], true)) {
            // Nothing from another site; the policy decides about the rest.
            return false;
        }

        if (!\in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
            return null;
        }

        $host = parse_url(str_replace('\\', '/', $url), \PHP_URL_HOST);

        return \is_string($host) && null !== Policy::asciiHost($host) ? str_replace('\\', '/', $url) : null;
    }

    /**
     * The declared service the URL loads from: null for none (the policy blocks it).
     */
    private function service(string $url): ?Service
    {
        foreach ($this->services->all() as $service) {
            foreach ($service->hosts as $sources) {
                foreach ($sources as $source) {
                    if (Policy::allows($source, $url)) {
                        return $service;
                    }
                }
            }
        }

        return null;
    }

    private function firstUrl(\Dom\Element $element): ?string
    {
        foreach ($this->urls($element) as $url) {
            $absolute = $this->absolute($url);

            if (\is_string($absolute) && 1 === preg_match('~^https?://~i', $absolute)) {
                return $absolute;
            }
        }

        return null;
    }

    private function placeholder(?Service $service, ?string $url, string $html): string
    {
        return ($this->twig)()->render('@quire_privacy/embed.html.twig', [
            'service' => $service,
            'url' => $url,
            'host' => null === $url ? null : Policy::asciiHost((string) parse_url($url, \PHP_URL_HOST)),
            'html' => $html,
            'test' => $this->banner->testMode(),
        ]);
    }
}
