<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\User\Security\Confirmation;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * "Confirm it's you" (D-42): the password of the person signed in, or, for someone without one, signing
 * in again. Then back to the admin page they were on, never another site.
 *
 * @internal
 */
final readonly class ConfirmController
{
    private const string CSRF_TOKEN = 'quire_admin_confirm';

    public function __construct(
        private Confirmation $confirmation,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $back = $this->back($request->isMethod('POST') ? $request->request->getString('back') : $request->query->getString('back'));
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
                throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
            }

            if ($this->confirmation->confirm($request->request->getString('password'))) {
                return new RedirectResponse($back, Response::HTTP_SEE_OTHER);
            }

            $error = 'That isn\'t your password, or there were too many tries: wait a few minutes.';
        }

        return new Response($this->twig->render('@quire_admin/confirm.html.twig', [
            'back' => $back,
            'by_password' => $this->confirmation->byPassword(),
            'error' => $error,
            'minutes' => intdiv(Confirmation::LIFETIME, 60),
        ]), null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * An admin page to go back to: the one given, or Home.
     */
    private function back(string $back): string
    {
        $admin = rtrim($this->urls->generate('quire_admin_dashboard'), '/');

        return (str_starts_with($back, $admin.'/') || $back === $admin) && !str_starts_with($back, '//') && !str_contains($back, '\\') ? $back : $this->urls->generate('quire_admin_dashboard');
    }
}
