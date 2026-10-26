<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Dashboard\Home;
use Quire\Admin\Dashboard\Inbox;
use Quire\Admin\Dashboard\WidgetInterface;
use Quire\Admin\Extend\Admin;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The admin's home: what needs the user, the week, what happened lately, the site's health, and the
 * cards extensions add; on a new site, its first steps.
 *
 * @internal
 */
final readonly class DashboardController
{
    /**
     * @param list<array{class: class-string<WidgetInterface>, ability: ?string, order: int, owner: string}> $widgets
     * @param ServiceLocator<WidgetInterface>                                                                $services
     */
    public function __construct(
        #[Autowire(param: Admin::WIDGETS_PARAMETER)]
        private array $widgets,
        #[AutowireLocator(Admin::WIDGET_TAG)]
        private ServiceLocator $services,
        private Security $security,
        private Environment $twig,
        private Inbox $inbox,
        private Home $home,
    ) {
    }

    public function __invoke(): Response
    {
        $widgets = $this->widgets;
        usort($widgets, static fn (array $a, array $b): int => [$a['order'], $a['class']] <=> [$b['order'], $b['class']]);
        $boxes = [];

        foreach ($widgets as $widget) {
            if (null !== $widget['ability'] && !$this->security->isGranted($widget['ability'])) {
                continue;
            }

            $service = $this->services->get($widget['class']);
            $html = $service->render();

            if ('' !== $html) {
                $boxes[] = ['title' => $service->title(), 'html' => $html];
            }
        }

        $now = new \DateTimeImmutable();
        $inbox = $this->inbox->items();
        $steps = $this->home->firstSteps();
        // A new site starts with its first steps, until something of it is live or nothing needs the user.
        $firstRun = null !== $steps && [] === $inbox && $this->home->isNew();

        return new Response($this->twig->render('@quire_admin/dashboard/index.html.twig', [
            'widgets' => $boxes,
            'inbox' => $inbox,
            'first_run' => $firstRun,
            'steps' => $steps,
            'greeting' => $this->home->greeting($now),
            'summary' => $firstRun ? null : $this->home->summary($now, \count($inbox)),
            'week' => $firstRun ? null : $this->home->week($now),
            'latest' => $this->home->latest(),
            'health' => $this->home->health(),
        ]));
    }
}
