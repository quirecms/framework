<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Site\SettingsForm;
use Quire\Settings\Exception\InvalidSettingValueException;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Settings\SettingsTransfer;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private SettingsTransfer $transfer,
    ) {
    }

    /**
     * The settings as a document, or a group of them as a form (e.g. without popovers).
     */
    public function __invoke(Request $request, ?string $group = null): Response
    {
        if (null !== $group) {
            return $this->page($this->group($group));
        }

        $this->group(null);
        $changed = [];

        foreach ($this->form->document() as $part) {
            foreach ($part['sentences'] as $sentence) {
                if ($sentence['changed']) {
                    $definition = $sentence['entry']['definition'];
                    $changed[] = ['label' => $sentence['entry']['label'], 'from' => $this->form->shown($definition, $definition->default, short: true), 'to' => $this->form->shown($definition, $this->settings->get($definition->key), short: true), 'key' => $definition->key];
                }
            }
        }

        return new Response($this->twig->render('@quire_admin/site/settings_document.html.twig', [
            'document' => $this->form->document(),
            'changed' => $changed,
            'exported' => $this->transfer->toYaml($this->transfer->export()),
            'back' => $request->getRequestUri(),
        ]));
    }

    /**
     * What the site changed, as a YAML file to keep or bring into another site.
     */
    public function export(): Response
    {
        $this->group(null);
        $response = new Response($this->transfer->toYaml($this->transfer->export()));
        $response->headers->set('Content-Type', 'application/yaml; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="settings.yaml"');

        return $response;
    }

    /**
     * A file of settings: first what it would change, to look at; then, confirmed, the change.
     */
    public function import(Request $request): Response
    {
        $this->group(null);
        $this->assertToken($request);
        $file = $request->files->get('file');
        $content = $file instanceof UploadedFile && $file->isValid() ? (string) file_get_contents($file->getPathname()) : $request->request->getString('content');

        try {
            $import = $this->transfer->plan($this->transfer->parse($content), skipUnknown: true);
        } catch (\InvalidArgumentException $e) {
            return $this->flashed($request, 'error', $e->getMessage());
        }

        if ($request->request->has('apply')) {
            if (!$import->canApply()) {
                return $this->flashed($request, 'error', implode(' ', $import->errors));
            }

            try {
                $count = $this->transfer->apply($import);
            } catch (InvalidSettingValueException $e) {
                return $this->flashed($request, 'error', $e->getMessage());
            }

            return $this->flashed($request, 'success', $this->translator->trans('{count, plural, =0 {Nothing changed.} one {# setting changed.} other {# settings changed.}}', ['count' => $count], 'quire-admin'));
        }

        $labels = [];

        foreach (array_keys($import->changes) as $key) {
            $definition = $this->schema->get($key);
            $labels[$key] = ['label' => $this->translator->trans($definition->label ?? $key, [], $definition->owner), 'from' => $this->form->shown($definition, $import->changes[$key]['from']), 'to' => $this->form->shown($definition, $import->changes[$key]['to'])];
        }

        return new Response($this->twig->render('@quire_admin/site/settings_import.html.twig', [
            'import' => $import,
            'changes' => $labels,
            'content' => $content,
        ]));
    }

    /**
     * Every setting the site changed back to its default, but the theme and permissions, which have pages of their own.
     */
    public function restoreDefaults(Request $request): RedirectResponse
    {
        $this->group(null);
        $this->assertToken($request);
        $count = 0;

        foreach ($this->form->document() as $part) {
            foreach ($part['sentences'] as $sentence) {
                if ($sentence['changed']) {
                    $this->settings->restoreDefault($sentence['entry']['definition']->key);
                    ++$count;
                }
            }
        }

        return $this->flashed($request, 'success', $this->translator->trans('{count, plural, =0 {Nothing changed.} one {# setting is back to its default.} other {# settings are back to their defaults.}}', ['count' => $count], 'quire-admin'));
    }

    public function save(Request $request, string $group): Response
    {
        $group = $this->group($group);

        $this->assertToken($request);
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

            return $this->back($group, $request);
        }

        $submitted = $request->request->all('settings');
        $result = $this->form->save($group, $submitted);

        if ([] !== $result['errors']) {
            return $this->page($group, $result['errors'], $result['values'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $session->getFlashBag()->add('success', [] === $result['changed']
            ? $this->translator->trans('Nothing changed.', [], 'quire-admin')
            : $this->translator->trans('{count, plural, one {# setting saved.} other {# settings saved.}}', ['count' => \count($result['changed'])], 'quire-admin'));

        return $this->back($group, $request);
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

    /**
     * Back to the page the form was on, e.g. the document; else the group's form.
     */
    private function back(string $group, Request $request): RedirectResponse
    {
        $back = $request->request->getString('back');
        $admin = rtrim($this->urls->generate('quire_admin_dashboard'), '/');

        return new RedirectResponse(str_starts_with($back, $admin.'/') && !str_starts_with($back, '//') ? $back : $this->urls->generate('quire_admin_settings', ['group' => $group]), Response::HTTP_SEE_OTHER);
    }

    private function flashed(Request $request, string $type, string $message): RedirectResponse
    {
        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add($type, $message);

        return new RedirectResponse($this->urls->generate('quire_admin_settings'), Response::HTTP_SEE_OTHER);
    }

    private function assertToken(Request $request): void
    {
        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }
    }
}
