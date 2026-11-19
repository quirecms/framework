<?php

declare(strict_types=1);

namespace Quire\Admin\Setup;

use Quire\Admin\Controller\ThemesController;
use Quire\Settings\Settings;
use Quire\Setup\SetupStep;
use Quire\Setup\SetupStepInterface;
use Quire\Theme\Themes;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Choose a look": done once a theme is chosen, rather than the default one.
 *
 * @internal
 */
final readonly class ThemeStep implements SetupStepInterface
{
    public function __construct(
        private Security $security,
        private Settings $settings,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function step(): ?SetupStep
    {
        if (!$this->security->isGranted(ThemesController::ABILITY)) {
            return null;
        }

        return new SetupStep(
            'quire-admin.theme',
            $this->translator->trans('Choose a look', [], 'quire-admin'),
            $this->translator->trans('See your site in each installed theme before you switch.', [], 'quire-admin'),
            'default' !== $this->settings->source(Themes::SETTING),
            $this->urls->generate('quire_admin_themes'),
            $this->translator->trans('Browse themes', [], 'quire-admin'),
        );
    }
}
