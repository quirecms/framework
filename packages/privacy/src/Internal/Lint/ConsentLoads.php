<?php

declare(strict_types=1);

namespace Quire\Privacy\Internal\Lint;

use Quire\Csp\Policy;
use Quire\Privacy\Service;
use Quire\Theme\TemplateUrls;

/**
 * Templates that load from a service visitors are asked about directly, by its URL: the site's policy
 * allows the service's hosts for every visitor, so such a load happens before the visitor consents.
 * The service's script (Privacy::service(script: …)) is what loads it, once allowed.
 *
 * Only URLs written in templates literally are seen, not those made in Twig.
 *
 * @internal
 */
final class ConsentLoads
{
    /**
     * @param iterable<string, string> $templates Files by the name to report them under
     * @param list<Service>            $services  Those visitors are asked about
     *
     * @return list<string> What's wrong, one message each
     */
    public static function find(iterable $templates, array $services): array
    {
        $problems = [];

        if ([] === $services) {
            return [];
        }

        foreach ($templates as $name => $file) {
            $source = @file_get_contents($file);

            if (!\is_string($source)) {
                continue;
            }

            foreach (TemplateUrls::find($source) as $found) {
                $service = self::service($found['url'], $services);

                if ($service instanceof Service) {
                    $problems[] = \sprintf('%s line %d: loads %s, from "%s" (%s), which visitors are asked about: it would load before they consent. Load it from the service\'s script instead (Privacy::service(script: …)), which runs once they allow it. See "Declaring a service" in docs/privacy.md.', $name, $found['line'], $found['url'], $service->label, $service->id);
                }
            }
        }

        return $problems;
    }

    /**
     * Every Twig template in the directories, by the path to report.
     *
     * @param iterable<string> $directories
     *
     * @return array<string, string>
     */
    public static function templates(iterable $directories, string $relativeTo = ''): array
    {
        $templates = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), '.twig')) {
                    $path = (string) $file->getRealPath();
                    $shown = '' !== $relativeTo && str_starts_with($path, $relativeTo) ? substr($path, \strlen($relativeTo)) : $path;
                    $templates[ltrim($shown, \DIRECTORY_SEPARATOR)] = $path;
                }
            }
        }

        ksort($templates);

        return $templates;
    }

    /**
     * @param list<Service> $services
     */
    private static function service(string $url, array $services): ?Service
    {
        foreach ($services as $service) {
            foreach ($service->hosts as $sources) {
                foreach ($sources as $source) {
                    if (Policy::allows($source, $url)) {
                        return $service;
                    }
                }
            }
        }

        return null;
    }
}
