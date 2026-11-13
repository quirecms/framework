<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard\Inbox;

use Quire\Admin\Controller\SetupController;
use Quire\Admin\Dashboard\InboxItem;
use Quire\Admin\Dashboard\InboxProviderInterface;
use Quire\Csp\Csp;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A production site whose security policy only reports blocks nothing: neither injected scripts nor
 * what quire/privacy holds back until visitors consent. Tried out, it shouldn't stay that way.
 *
 * @internal
 */
final readonly class ReportOnlyInbox implements InboxProviderInterface
{
    public function __construct(
        private Csp $csp,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire(param: 'kernel.environment')]
        private string $environment = 'prod',
    ) {
    }

    public function items(): iterable
    {
        if ('prod' !== $this->environment || !$this->csp->reportOnly()) {
            return;
        }

        yield new InboxItem(
            key: 'security-report-only',
            text: $this->translator->trans('The site\'s **security policy only reports**: it blocks nothing', [], 'quire-admin'),
            url: $this->urls->generate(SetupController::ROUTE),
            action: $this->translator->trans('See the setup', [], 'quire-admin'),
            at: new \DateTimeImmutable('today'),
            priority: 25,
            tags: [['label' => $this->translator->trans('report only', [], 'quire-admin'), 'tone' => InboxItem::TONE_DANGER]],
            detail: $this->translator->trans('Neither injected scripts nor what visitors haven\'t allowed are blocked. Whoever runs the site sets "quire.csp.mode" back to "enforce".', [], 'quire-admin'),
        );
    }
}
