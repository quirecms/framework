<?php

declare(strict_types=1);

namespace Quire\Analytics\Internal;

use Quire\Content\Event\ContentViewing;
use Quire\Theme\SlotRendererInterface;
use Quire\Theme\ThemePreview;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Pages that aren't counted, whatever the visitor allowed: previews of unpublished items, the site in a
 * theme someone is trying, and error pages. The head says so (<meta name="quire-analytics"
 * content="off">), and providers send nothing there. Pages with forms for passwords or accounts have
 * no loader at all (quire/privacy), and the admin none either.
 *
 * @internal
 */
final readonly class NotCounted implements SlotRendererInterface
{
    private const string ATTRIBUTE = '_quire_analytics_off';

    public function __construct(private RequestStack $requests, private ThemePreview $themePreview)
    {
    }

    #[AsEventListener]
    public function onContentViewing(ContentViewing $event): void
    {
        if (!$event->content->isPublic()) {
            $event->request->attributes->set(self::ATTRIBUTE, true);
        }
    }

    public function render(array $context): string
    {
        $main = $this->requests->getMainRequest();
        $current = $this->requests->getCurrentRequest();
        $off = true === $main?->attributes->get(self::ATTRIBUTE)
            // An error page, rendered with the exception.
            || null !== $current?->attributes->get('exception')
            || null !== $this->themePreview->current();

        return $off ? '<meta name="quire-analytics" content="off">' : '';
    }
}
