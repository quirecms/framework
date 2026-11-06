<?php

declare(strict_types=1);

namespace Quire\Admin\Security;

use Quire\User\Security\Confirmation;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends someone to "Confirm it's you" before a page or a form that changes who may do what, or runs new
 * code (D-42), and back to the page afterwards. Pages ask it when they open, so a form isn't lost; the
 * form asks again when it's sent, in case the confirmation ran out meanwhile.
 *
 * @internal
 */
final readonly class ConfirmGuard
{
    public function __construct(
        private Confirmation $confirmation,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Null when they confirmed in the last few minutes, otherwise the way to the confirmation, which
     * brings them back to the page (for a form sent: the page it's on).
     */
    public function required(Request $request, ?string $back = null): ?RedirectResponse
    {
        if ($this->confirmation->isFresh()) {
            return null;
        }

        $back ??= $request->isMethod('GET') ? $request->getRequestUri() : ($request->headers->get('referer') ?? $this->urls->generate('quire_admin_dashboard'));

        return new RedirectResponse($this->urls->generate('quire_admin_confirm', ['back' => $back]), Response::HTTP_SEE_OTHER);
    }
}
