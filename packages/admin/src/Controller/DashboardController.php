<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Dashboard\WidgetInterface;
use Quire\Admin\Extend\Admin;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * The admin's start page: the widgets the user may see.
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

        return new Response($this->twig->render('@quire_admin/dashboard/index.html.twig', ['widgets' => $boxes]));
    }
}
