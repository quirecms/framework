<?php

declare(strict_types=1);

namespace Quire\Admin\Audit;

use Quire\Admin\Preferences\UnderTheHood;
use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Extend\Routes;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * After an action in the admin (a form sent, then a redirect), shows the console commands that do the
 * same, from what the audit log recorded during the request: the admin and the command line run the
 * same services, so anything clicked can be scripted. Shown with "Under the hood" on.
 *
 * @internal
 */
#[AsEventListener]
final readonly class AsCommandListener
{
    /** More are summed up in a comment line, which a shell ignores */
    private const int LIMIT = 5;

    public function __construct(
        private AuditLog $log,
        private UnderTheHood $hood,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !$request->isMethod('POST') || !$event->getResponse() instanceof RedirectResponse
            || Routes::ADMIN !== $request->attributes->get(Routes::GROUP_ATTRIBUTE) || !$request->hasSession()) {
            return;
        }

        $commands = array_values(array_unique(array_filter(array_map(static fn (AuditEntry $entry): ?string => $entry->command, $this->log->recordedNow()), \is_string(...))));
        $session = $request->getSession();

        if ([] === $commands || !$session instanceof FlashBagAwareSessionInterface || !$this->hood->isOn($request)) {
            return;
        }

        $more = \count($commands) - self::LIMIT;
        $shown = \array_slice($commands, 0, self::LIMIT);

        if ($more > 0) {
            $shown[] = '# '.$this->translator->trans('{count, plural, other {# more in the audit log}}', ['count' => $more], 'quire-admin');
        }

        $session->getFlashBag()->set('command', $shown);
    }
}
