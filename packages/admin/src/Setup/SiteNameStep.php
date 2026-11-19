<?php

declare(strict_types=1);

namespace Quire\Admin\Setup;

use Quire\Admin\Controller\SettingsController;
use Quire\Admin\Twig\RelativeTime;
use Quire\Settings\Settings;
use Quire\Setup\SetupStep;
use Quire\Setup\SetupStepInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Name your site": done once its title isn't the default.
 *
 * @internal
 */
final readonly class SiteNameStep implements SetupStepInterface
{
    public function __construct(
        private Security $security,
        private Settings $settings,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private RelativeTime $time,
    ) {
    }

    public function step(): ?SetupStep
    {
        if (!$this->security->isGranted(SettingsController::ABILITY)) {
            return null;
        }
        $named = 'default' !== $this->settings->source('site.title');

        return new SetupStep(
            'quire-admin.site-name',
            $this->translator->trans('Name your site', [], 'quire-admin'),
            $named
                ? $this->translator->trans('“{title}”, in {locale}, {timezone} time.', ['title' => $this->settings->getString('site.title'), 'locale' => \Locale::getDisplayLanguage($this->settings->getString('site.locale'), $this->translator->getLocale()), 'timezone' => $this->time->timezone()->getName()], 'quire-admin')
                : $this->translator->trans('Its name, language and time zone.', [], 'quire-admin'),
            $named,
            $this->urls->generate('quire_admin_settings', ['group' => 'site']),
            $this->translator->trans('Name it', [], 'quire-admin'),
        );
    }
}
