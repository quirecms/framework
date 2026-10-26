<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Action\Actions;
use Quire\Action\Exception\ActionDeniedException;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Admin\Intent\Finder;
use Quire\Admin\Intent\IntentParser;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The intent bar: what the words ask to do, with its plan, and what they name. A page of its own
 * without JavaScript; with it, the bar asks for the results only, as the person types.
 *
 * @internal
 */
final readonly class IntentController
{
    private const string CSRF_TOKEN = 'quire_admin_intent';

    public function __construct(
        private IntentParser $parser,
        private Finder $finder,
        private Actions $actions,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $words = mb_substr(trim($request->query->getString('q')), 0, 300);
        $variables = ['q' => $words, 'intent' => '' === $words ? null : $this->parser->parse($words), 'found' => $this->finder->find($words)];

        return new Response($this->twig->render('fetch' === $request->headers->get('X-Quire-Request') ? '@quire_admin/intent/_results.html.twig' : '@quire_admin/intent/index.html.twig', $variables));
    }

    /**
     * Does what the words ask, as they're understood now.
     */
    public function do(Request $request): RedirectResponse
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        $words = trim($request->request->getString('q'));
        $intent = $this->parser->parse($words);
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

        if (!$intent instanceof \Quire\Admin\Intent\Intent || null !== $intent->denied) {
            $session->getFlashBag()->add('error', $intent->denied ?? $this->translator->trans('That isn’t something the admin understands yet.', [], 'quire-admin'));

            return new RedirectResponse($this->urls->generate('quire_admin_find', ['q' => $words]), Response::HTTP_SEE_OTHER);
        }

        try {
            $this->actions->run($intent->action, $intent->arguments);
            $session->getFlashBag()->add('success', $this->translator->trans('Done: {what}', ['what' => implode(' ', $intent->steps)], 'quire-admin'));
        } catch (ActionDeniedException $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        } catch (InvalidArgumentsException $e) {
            $session->getFlashBag()->add('error', implode(' ', $e->errors));
        } catch (\RuntimeException|\InvalidArgumentException|\LogicException $e) {
            $session->getFlashBag()->add('error', $e->getMessage());
        }

        return new RedirectResponse($intent->change ?? $this->urls->generate('quire_admin_dashboard'), Response::HTTP_SEE_OTHER);
    }
}
