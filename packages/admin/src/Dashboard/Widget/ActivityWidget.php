<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard\Widget;

use Quire\Admin\Audit\AuditEntries;
use Quire\Admin\Dashboard\WidgetInterface;
use Quire\Audit\AuditLog;
use Quire\Settings\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * What was done last on the site, for those who may see the audit log.
 *
 * @internal
 */
final readonly class ActivityWidget implements WidgetInterface
{
    private const int LIMIT = 6;

    public function __construct(
        private AuditLog $log,
        private AuditEntries $entries,
        private Settings $settings,
        private Security $security,
        private Environment $twig,
        private TranslatorInterface $translator,
    ) {
    }

    public function title(): string
    {
        return $this->translator->trans('Recent activity', [], 'quire-admin');
    }

    public function render(): string
    {
        if (!$this->security->isGranted(AuditLog::ABILITY)) {
            return '';
        }

        $timezone = $this->settings->getString('site.timezone');

        return $this->twig->render('@quire_admin/dashboard/activity.html.twig', [
            'entries' => $this->entries->present($this->log->find(perPage: self::LIMIT), $timezone),
            'timezone' => $timezone,
        ]);
    }
}
