<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Preferences\Density;
use Quire\Admin\Preferences\UnderTheHood;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * A person's own settings of the admin, e.g. "Under the hood". Works without JavaScript: the form
 * posts here, and the page it came from opens again.
 *
 * @internal
 */
final readonly class PreferencesController
{
    public const string CSRF_TOKEN = 'quire_admin_preferences';

    public function __construct(
        private UnderTheHood $hood,
        private Density $density,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function hood(Request $request): RedirectResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        $admin = $this->adminPath();
        $response = new RedirectResponse($this->back($request, $admin), Response::HTTP_SEE_OTHER);
        $response->headers->setCookie($this->hood->cookie(!$this->hood->isOn($request), $admin));

        return $response;
    }

    public function density(Request $request): RedirectResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        $admin = $this->adminPath();
        $response = new RedirectResponse($this->back($request, $admin), Response::HTTP_SEE_OTHER);
        $response->headers->setCookie($this->density->cookie($request->request->getString('density'), $admin));

        return $response;
    }

    /**
     * The admin page the form was on; never another site.
     */
    private function back(Request $request, string $admin): string
    {
        $back = $request->request->getString('back');

        return str_starts_with($back, $admin) && !str_starts_with($back, '//') ? $back : $this->urls->generate('quire_admin_dashboard');
    }

    /**
     * Where the admin is, e.g. "/admin" (it can be moved, see "quire.routing.admin_prefix").
     */
    private function adminPath(): string
    {
        return rtrim($this->urls->generate('quire_admin_dashboard'), '/');
    }
}
