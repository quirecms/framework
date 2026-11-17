<?php

/*
 * Checks that Packagist has every package Split pushes (.github/split.json), under the name in its
 * composer.json and from the repository Split pushes it to. A version tag reaches Packagist through
 * Split, but only for a package Packagist already knows: a new one (quirecms/privacy and analytics in
 * 0.3.0) has to be submitted first, or the release's feed names versions that install from nowhere.
 * Run it before tagging; the release workflow runs it first, and publishes nothing when it fails.
 *
 *   php .github/packagist.php [--root=<dir>] [--packagist=<url>]
 *
 * --root       the framework's checkout (this one by default)
 * --packagist  where Packagist is (https://packagist.org by default; tests give a folder, file://…)
 *
 * Plain PHP 8.4 or newer, no dependencies: the workflow runs it before anything is installed.
 */

declare(strict_types=1);

$options = getopt('', ['root:', 'packagist:']);
$root = rtrim(is_string($options['root'] ?? null) ? $options['root'] : dirname(__DIR__), '/');
$packagist = rtrim(is_string($options['packagist'] ?? null) ? $options['packagist'] : 'https://packagist.org', '/');
$github = getenv('GITHUB_ACTIONS') === 'true';

/** @return array<mixed>|null The JSON object, as an array; null for anything else */
$object = static function (string $json): ?array {
    try {
        $value = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($value) && !array_is_list($value) ? $value : null;
};

/**
 * A package's page on Packagist, tried 3 times when Packagist doesn't answer or answers with an error
 * of its own (5xx, 429).
 *
 * @return array{int, string} Its HTTP status (404 when it isn't there; 0 when there's no answer), and its body
 */
$fetch = static function (string $url): array {
    if (str_starts_with($url, 'file://')) {
        $body = @file_get_contents($url);

        return false === $body ? [404, ''] : [200, $body];
    }

    $context = stream_context_create(['http' => [
        'timeout' => 20,
        'ignore_errors' => true,
        'user_agent' => 'quirecms-release-check (https://github.com/quirecms/framework)',
    ]]);
    $status = 0;
    $body = '';

    for ($attempt = 1; $attempt <= 3; ++$attempt) {
        $body = @file_get_contents($url, false, $context);
        $status = 1 === preg_match('#^HTTP/\S+ (\d{3})#', (http_get_last_response_headers() ?? [])[0] ?? '', $match) ? (int) $match[1] : 0;

        if (0 !== $status && 429 !== $status && $status < 500) {
            break;
        }

        if ($attempt < 3) {
            sleep(10);
        }
    }

    return [$status, false === $body ? '' : $body];
};

$split = json_decode((string) @file_get_contents($root.'/.github/split.json'), true);

if (!is_array($split) || !array_is_list($split) || [] === $split) {
    fwrite(\STDERR, ($github ? '::error::' : '').$root."/.github/split.json lists no packages.\n");
    exit(1);
}

$problems = [];
$checked = [];

foreach ($split as $index => $entry) {
    $path = is_array($entry) && is_string($entry['path'] ?? null) ? $entry['path'] : null;
    $repository = is_array($entry) && is_string($entry['repository'] ?? null) ? $entry['repository'] : null;

    // An entry that names nothing is a problem, not one to skip: Split would push it anyway.
    if (null === $path || null === $repository || 1 !== preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
        $problems[] = sprintf('.github/split.json\'s entry %d has no path or no repository (owner/name).', $index + 1);

        continue;
    }

    // Packagist knows a package by the name in its composer.json, not by its repository's.
    $composer = $object((string) @file_get_contents($root.'/'.$path.'/composer.json'));
    $name = is_string($composer['name'] ?? null) ? $composer['name'] : null;

    if (null === $name || 1 !== preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $name)) {
        $problems[] = sprintf('%s/composer.json has no package name.', $path);

        continue;
    }

    [$status, $body] = $fetch($packagist.'/packages/'.$name.'.json');
    $package = 200 === $status ? ($object($body)['package'] ?? null) : null;
    $source = is_array($package) && is_string($package['repository'] ?? null) ? $package['repository'] : null;
    $expected = 'https://github.com/'.$repository;

    if (404 === $status) {
        $problems[] = sprintf('Packagist has no %s: submit %s at https://packagist.org/packages/submit before tagging.', $name, $expected);
    } elseif (200 !== $status || !is_array($package)) {
        $problems[] = sprintf('Packagist didn\'t say whether it has %s (%s): try again.', $name, 0 === $status ? 'no answer' : 'HTTP '.$status);
    } elseif (null === $source || strtolower((string) preg_replace('#\.git$#', '', $source)) !== strtolower($expected)) {
        // GitHub's names don't tell case apart: QuireCMS/core is quirecms/core.
        $problems[] = sprintf('Packagist has %s from "%s", not from %s, where Split pushes it.', $name, $source ?? '', $expected);
    } elseif (false !== ($package['abandoned'] ?? false)) {
        $problems[] = sprintf('Packagist marks %s as abandoned: Composer warns everyone who installs it.', $name);
    } else {
        $checked[] = $name;
    }
}

if ([] !== $problems) {
    foreach ($problems as $problem) {
        fwrite(\STDERR, ($github ? '::error::' : '').$problem."\n");
    }

    exit(1);
}

echo sprintf("Packagist has every package Split pushes, from its repository: %s.\n", implode(', ', $checked));
