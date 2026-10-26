<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Extension\ExtensionManifest;
use Quire\Extension\Rebuild\RebuildFailedException;
use Quire\Theme\Exception\ThemeException;
use Quire\Theme\ThemePreview;
use Quire\Theme\Themes;
use Quire\Theme\ThemeSwitcher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
 * The installed themes, and changing the site's: what quire:theme:use does, through the same service.
 *
 * @internal
 */
final readonly class ThemesController
{
    public const string ABILITY = 'quire.themes.manage';

    private const string CSRF_TOKEN = 'quire_admin_site';

    public function __construct(
        private Themes $themes,
        private ThemeSwitcher $switcher,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
        private ThemePreview $preview,
    ) {
    }

    public function __invoke(): Response
    {
        $this->assertAllowed();
        $installed = $this->themes->installed();
        $themes = [];

        foreach ($installed as $id => $theme) {
            try {
                $this->themes->check($id);
                $problem = null;
            } catch (ThemeException $e) {
                $problem = $e->getMessage();
            }

            $themes[] = [
                'manifest' => $theme,
                'parent' => null === $theme->parent ? null : ($installed[$theme->parent]->title ?? $theme->parent),
                'active' => $id === $this->themes->active()?->id,
                'problem' => $problem,
                'screenshot' => null !== $this->screenshotFile($theme),
                // Already the site's look: nothing to preview.
                'preview' => $id === $this->themes->active()?->id ? null : $this->preview->reason($id),
            ];
        }

        usort($themes, static fn (array $a, array $b): int => [!$a['active'], $a['manifest']->title] <=> [!$b['active'], $b['manifest']->title]);

        return new Response($this->twig->render('@quire_admin/site/themes.html.twig', [
            'themes' => $themes,
            'builtin' => !$this->themes->active() instanceof ExtensionManifest,
            'problem' => $this->themes->problem(),
            'read_only' => $this->themes->readOnlyReason(),
        ]));
    }

    public function use(Request $request): RedirectResponse
    {
        $this->assertAllowed();

        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $id = $request->request->getString('theme');
        $id = '' === $id ? null : $id;
        $title = null === $id ? null : ($this->themes->installed()[$id] ?? null)?->title;

        try {
            $this->switcher->use($id, static function (): void {});
            $session->getFlashBag()->add('success', null === $id
                ? $this->translator->trans('The site uses Quire\'s built-in templates now.', [], 'quire-admin')
                : $this->translator->trans('The site uses “{theme}” now.', ['theme' => $title ?? $id], 'quire-admin'));
        } catch (ThemeException|RebuildFailedException $e) {
            $session->getFlashBag()->add('error', $e->getMessage());
        }

        return new RedirectResponse($this->urls->generate('quire_admin_themes'), Response::HTTP_SEE_OTHER);
    }

    /**
     * A theme's screenshot (see "extra.quire.theme.screenshot" of its composer.json).
     */
    public function screenshot(string $id): BinaryFileResponse
    {
        $this->assertAllowed();
        $theme = $this->themes->installed()[$id] ?? throw new NotFoundHttpException(\sprintf('No theme "%s" is installed.', $id));
        $file = $this->screenshotFile($theme) ?? throw new NotFoundHttpException(\sprintf('The theme "%s" has no screenshot.', $id));
        $response = new BinaryFileResponse($file, headers: ['Cache-Control' => 'private, max-age=86400']);
        $response->setAutoEtag();

        return $response;
    }

    /**
     * The theme's screenshot file, when it has one: an image inside the theme's directory.
     */
    private function screenshotFile(ExtensionManifest $theme): ?string
    {
        $path = $theme->screenshotPath();
        $real = null === $path ? false : realpath($path);
        $root = realpath($theme->path);

        return false !== $real && false !== $root && str_starts_with($real, $root.\DIRECTORY_SEPARATOR) && 1 === preg_match('/\.(png|jpe?g|webp)$/i', $real) ? $real : null;
    }

    private function assertAllowed(): void
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException($this->translator->trans('Changing the site\'s theme takes the right to do so.', [], 'quire-admin'));
        }
    }
}
