<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Extension\Exception\DependencyException;
use Quire\Extension\Exception\ExtensionStateException;
use Quire\Extension\Exception\UnknownExtensionException;
use Quire\Extension\ExtensionManager;
use Quire\Extension\ExtensionManifest;
use Quire\Extension\ExtensionSwitcher;
use Quire\Extension\InstalledExtensions;
use Quire\Extension\Rebuild\RebuildFailedException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The installed extensions: enabling, disabling and purging them, each after a page that says what else
 * it changes, through the same service as quire:extension:enable and quire:extension:disable.
 *
 * @internal
 */
final readonly class ExtensionsController
{
    public const string ABILITY = 'quire.extensions.manage';

    public const array ACTIONS = ['enable', 'disable', 'purge'];

    private const string CSRF_TOKEN = 'quire_admin_site';

    public function __construct(
        private InstalledExtensions $installed,
        private ExtensionManager $extensions,
        private ExtensionSwitcher $switcher,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        $this->assertAllowed();
        $resolved = $this->extensions->resolve();
        $extensions = array_map(static fn (ExtensionManifest $manifest): array => [
            'manifest' => $manifest,
            'status' => match (true) {
                $resolved->isRunning($manifest->id) => 'enabled',
                isset($resolved->broken[$manifest->id]) => 'broken',
                default => 'disabled',
            },
            'problem' => $resolved->broken[$manifest->id] ?? null,
        ], array_values($this->installed->all()));

        usort($extensions, static fn (array $a, array $b): int => $a['manifest']->title <=> $b['manifest']->title);

        return new Response($this->twig->render('@quire_admin/site/extensions.html.twig', [
            'extensions' => $extensions,
            'missing' => $resolved->missing,
            'read_only' => $this->extensions->readOnlyReason(),
        ]));
    }

    /**
     * What the action changes, to confirm.
     */
    public function confirm(string $id, string $action): Response
    {
        $this->assertAllowed();
        $manifest = $this->manifest($id);

        try {
            $plan = $this->plan($id, $action);
        } catch (UnknownExtensionException|DependencyException|ExtensionStateException $e) {
            return $this->confirmPage($manifest, $action, [], $e->getMessage());
        }

        return $this->confirmPage($manifest, $action, $plan, 'purge' === $action ? $this->switcher->purgeProblem($plan) : null);
    }

    public function act(Request $request, string $id, string $action): Response
    {
        $this->assertAllowed();
        $manifest = $this->manifest($id);

        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        if ('purge' === $action && $manifest->id !== trim($request->request->getString('confirm'))) {
            return $this->confirmPage($manifest, $action, $this->plan($id, $action), null, Response::HTTP_UNPROCESSABLE_ENTITY, $this->translator->trans('Type the extension\'s ID to confirm that its data is deleted.', [], 'quire-admin'));
        }

        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $log = '';
        $output = static function (string $text) use (&$log): void {
            $log .= $text;
        };

        try {
            $done = 'enable' === $action ? $this->switcher->enable([$id], $output) : $this->switcher->disable([$id], 'purge' === $action, $output);
            $session->getFlashBag()->add('success', $this->translator->trans('extensions.done.'.$action, ['ids' => implode(', ', $done)], 'quire-admin'));
        } catch (UnknownExtensionException|DependencyException|ExtensionStateException|RebuildFailedException $e) {
            $session->getFlashBag()->add('error', $e->getMessage());
        }

        return new RedirectResponse($this->urls->generate('quire_admin_extensions'), Response::HTTP_SEE_OTHER);
    }

    /**
     * @return list<string> The IDs of the extensions the action changes
     */
    private function plan(string $id, string $action): array
    {
        return 'enable' === $action
            ? array_map(static fn (ExtensionManifest $manifest): string => $manifest->id, $this->extensions->planEnable([$id]))
            : $this->extensions->planDisable([$id]);
    }

    /**
     * @param list<string> $plan
     */
    private function confirmPage(ExtensionManifest $manifest, string $action, array $plan, ?string $problem, int $status = Response::HTTP_OK, ?string $error = null): Response
    {
        $titles = [];

        foreach ($plan as $planned) {
            $titles[$planned] = $this->installed->all()[$planned]->title ?? $planned;
        }

        return new Response($this->twig->render('@quire_admin/site/extension_confirm.html.twig', [
            'extension' => $manifest,
            'action' => $action,
            'plan' => $titles,
            'others' => array_diff_key($titles, [$manifest->id => true]),
            'problem' => $problem ?? $this->extensions->readOnlyReason(),
            'error' => $error,
        ]), $status);
    }

    private function manifest(string $id): ExtensionManifest
    {
        return $this->installed->all()[$id] ?? throw new NotFoundHttpException(\sprintf('No extension "%s" is installed.', $id));
    }

    private function assertAllowed(): void
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException($this->translator->trans('Managing extensions takes the right to do so.', [], 'quire-admin'));
        }
    }
}
