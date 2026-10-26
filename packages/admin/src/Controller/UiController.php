<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Quire UI's components, as a living style guide: what extensions' admin pages can use, with the
 * markup to copy. Also the page the visual tests compare to their baselines.
 *
 * @internal
 */
final readonly class UiController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        return new Response($this->twig->render('@quire_admin/ui/index.html.twig'));
    }
}
