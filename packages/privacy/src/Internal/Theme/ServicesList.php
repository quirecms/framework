<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Theme;

use Quire\Privacy\Service;
use Quire\Privacy\Services;
use Twig\Environment;

/**
 * The list of the site's services for its privacy notice, from their declarations, so it can't be out
 * of date.
 *
 * @internal
 */
final readonly class ServicesList
{
    public function __construct(private Services $services, private Environment $twig)
    {
    }

    public function render(): string
    {
        $categories = [];

        foreach ($this->services->categories() as $category) {
            $categories[] = ['category' => $category, 'services' => array_values(array_filter($this->services->askedAbout(), static fn (Service $service): bool => $category === $service->category))];
        }

        return $this->twig->render('@quire_privacy/services.html.twig', [
            'categories' => $categories,
            'necessary' => array_values(array_filter($this->services->all(), static fn (Service $service): bool => !$service->category->needsConsent())),
        ]);
    }
}
