<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Site\SettingsForm;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
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
 * The site's settings, a group at a time, for those with "quire.settings.manage".
 *
 * @internal
 */
final readonly class SettingsController
{
    public const string ABILITY = 'quire.settings.manage';

    private const string CSRF_TOKEN = 'quire_admin_site';

    public function __construct(
        private SettingsForm $form,
        private Settings $settings,
        private SettingsSchema $schema,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function __invoke(?string $group = null): Response
    {
        return $this->page($this->group($group));
    }

    public function save(Request $request, string $group): Response
    {
        $group = $this->group($group);

        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $default = $request->request->getString('default');

        if ('' !== $default) {
            try {
                $this->settings->restoreDefault($this->schema->get($default)->key);
                $session->getFlashBag()->add('success', $this->translator->trans('The setting is back to its default.', [], 'quire-admin'));
            } catch (InvalidSettingValueException $e) {
                $session->getFlashBag()->add('error', $e->getMessage());
            }

            return $this->back($group);
        }

        $submitted = $request->request->all('settings');
        $result = $this->form->save($group, $submitted);

        if ([] !== $result['errors']) {
            return $this->page($group, $result['errors'], $result['values'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $session->getFlashBag()->add('success', [] === $result['changed']
            ? $this->translator->trans('Nothing changed.', [], 'quire-admin')
            : $this->translator->trans('{count, plural, one {# setting saved.} other {# settings saved.}}', ['count' => \count($result['changed'])], 'quire-admin'));

        return $this->back($group);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $values
     */
    private function page(string $group, array $errors = [], array $values = [], int $status = Response::HTTP_OK): Response
    {
        return new Response($this->twig->render('@quire_admin/site/settings.html.twig', [
            'groups' => $this->form->groups(),
            'group' => $group,
            'entries' => $this->form->entries($group, $errors, $values),
            'errors' => $errors,
            'ids' => array_combine(array_keys($errors), array_map($this->form->id(...), array_keys($errors))),
        ]), $status);
    }

    private function group(?string $group): string
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException($this->translator->trans('Changing the site\'s settings takes the right to do so.', [], 'quire-admin'));
        }

        $groups = $this->form->groups();

        if (null === $group) {
            return (string) array_key_first($groups);
        }

        return isset($groups[$group]) ? $group : throw new NotFoundHttpException(\sprintf('There\'s no group of settings "%s".', $group));
    }

    private function back(string $group): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate('quire_admin_settings', ['group' => $group]), Response::HTTP_SEE_OTHER);
    }
}
