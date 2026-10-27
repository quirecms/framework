<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Preferences\AdminPreferences;
use Quire\Admin\Preferences\UnderTheHood;
use Quire\User\CurrentUser;
use Quire\User\Preference\UserPreferences;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * A person's own preferences of the admin: the sheet of them (a page of its own without JavaScript),
 * and the "Under the hood" switch on other pages. With JavaScript, the sheet saves
 * each change as it's made and gets "204 No Content" back; otherwise the page it came from opens again.
 *
 * @internal
 */
final readonly class PreferencesController
{
    public const string CSRF_TOKEN = 'quire_admin_preferences';

    public function __construct(
        private AdminPreferences $preferences,
        private UserPreferences $userPreferences,
        private UnderTheHood $hood,
        private CurrentUser $users,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return new Response($this->twig->render('@quire_admin/preferences.html.twig'));
        }

        $user = $this->userId($request);
        $this->preferences->save($user, $request->request->all());

        // A checkbox says nothing when it's off: the form says it was there.
        if ($request->request->has('hood_shown')) {
            $this->userPreferences->set($user, AdminPreferences::HOOD, $request->request->has('hood'));
        }

        return 'fetch' === $request->headers->get('X-Quire-Request') ? new Response(null, Response::HTTP_NO_CONTENT) : $this->back($request);
    }

    public function hood(Request $request): RedirectResponse
    {
        $this->userPreferences->set($this->userId($request), AdminPreferences::HOOD, !$this->hood->isOn());

        return $this->back($request);
    }

    /**
     * Whose preferences: the signed-in person's, from a form of theirs.
     */
    private function userId(Request $request): string
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        return $this->users->get()->id ?? throw new AccessDeniedHttpException('Preferences are kept for Quire\'s users.');
    }

    /**
     * Back to the admin page the form was on; never another site.
     */
    private function back(Request $request): RedirectResponse
    {
        $admin = rtrim($this->urls->generate('quire_admin_dashboard'), '/');
        $back = $request->request->getString('back');

        return new RedirectResponse(str_starts_with($back, $admin) && !str_starts_with($back, '//') ? $back : $this->urls->generate('quire_admin_dashboard'), Response::HTTP_SEE_OTHER);
    }
}
