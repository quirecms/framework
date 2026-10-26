<?php

declare(strict_types=1);

namespace Quire\Admin\Preferences;

use Quire\Extend\Routes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The admin in the language each person reads it in (their preference "quire.language"), when the admin
 * speaks it; otherwise in the site's. After the firewall (8), which tells who they are.
 *
 * @internal
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final readonly class AdminLocaleListener
{
    public function __construct(
        private AdminPreferences $preferences,
        private LocaleSwitcher $locales,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || Routes::ADMIN !== $request->attributes->get(Routes::GROUP_ATTRIBUTE)) {
            return;
        }

        $language = $this->preferences->language();

        if (null !== $language) {
            $request->setLocale($language);
            $this->locales->setLocale($language);
        }
    }
}
