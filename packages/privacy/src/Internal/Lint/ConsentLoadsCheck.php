<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Lint;

use Quire\Extend\Views;
use Quire\Privacy\Services;
use Quire\Theme\ThemeCheckInterface;
use Quire\Theme\ThemeProblem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * For "quire:theme:lint" and the admin's themes page: templates of the theme, and of the enabled
 * extensions, that load a service visitors are asked about directly, before they consent. The site
 * refuses to build with one (ConsentLoadsPass); this says so for a theme before it's used, and for
 * templates changed since the site was built.
 *
 * @internal
 */
final readonly class ConsentLoadsCheck implements ThemeCheckInterface
{
    /**
     * @param list<array{namespace: string, directory: string, owner: string}> $views Extensions' templates
     */
    public function __construct(
        private Services $services,
        #[Autowire(param: Views::PARAMETER)]
        private array $views = [],
    ) {
    }

    public function check(string $theme, array $templates): array
    {
        $extensions = [];

        foreach ($this->views as $view) {
            foreach (ConsentLoads::templates([$view['directory']], $view['directory']) as $name => $file) {
                $extensions[\sprintf('@%s/%s (%s)', $view['namespace'], $name, $view['owner'])] = $file;
            }
        }

        return array_map(ThemeProblem::error(...), ConsentLoads::find([...$templates, ...$extensions], $this->services->askedAbout()));
    }
}
