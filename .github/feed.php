<?php

/*
 * Builds Quire's release feed (feed.json): every release up to this one, from its notes in
 * docs/releases/<version>.md and its tag, for tools that update sites (Quire Server, a site's own
 * updater). The release workflow signs it and publishes it with the release.
 *
 *   php .github/feed.php --version=0.1.2 --skeleton=<dir> --published=<dir> [--root=<dir>]
 *   php .github/feed.php --version=0.1.2 --release-notes [--root=<dir>]   (the GitHub release's text)
 *
 * --skeleton   this version's skeleton, as .github/skeleton.sh builds it
 * --published  a clone of quirecms/skeleton, with its tags: earlier versions' skeletons
 * --root       the framework's checkout (this one by default), with every release's tag
 *
 * Each release says: its date, notes, upgrade notes, the security advisories it fixes, what it
 * requires (PHP, PostgreSQL's major version, the server contract) and its skeleton's files by
 * SHA-256 (what a site got from the skeleton, to tell the files it never edited), with those that
 * changed since the release before. The output depends only on its inputs: building it again gives
 * the same file.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require __DIR__.'/../vendor/autoload.php';

const VERSION = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$/';
const ADVISORY = '/^(GHSA(-[23456789cfghjmpqrvwx]{4}){3}|CVE-\d{4}-\d{4,})$/';
const SEVERITIES = ['low', 'moderate', 'high', 'critical'];

function fail(string $message): never
{
    fwrite(\STDERR, 'feed: '.$message."\n");
    exit(1);
}

/**
 * @param list<string> $command
 */
function run(array $command, string $directory): string
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory);

    if (!is_resource($process)) {
        fail('Can\'t run '.$command[0].'.');
    }

    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);

    if (0 !== proc_close($process)) {
        fail(implode(' ', $command).' failed: '.trim($error));
    }

    return $output;
}

/**
 * A file at a release's tag, or null when it has none.
 */
function at(string $root, string $version, string $path): ?string
{
    $paths = run(['git', 'ls-tree', '--name-only', 'v'.$version.'^{commit}', '--', $path], $root);

    return '' === trim($paths) ? null : run(['git', 'show', 'v'.$version.'^{commit}:'.$path], $root);
}

/**
 * @return array{security: list<array{id: string, severity: string, package: string, summary: string}>, upgrade: ?string, notes: string}
 */
function notes(string $file): array
{
    $content = (string) file_get_contents($file);

    if (1 !== preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', str_replace("\r\n", "\n", $content), $parts)) {
        fail($file.' must start with its header between "---" lines.');
    }

    try {
        $header = Yaml::parse($parts[1]);
    } catch (ParseException $e) {
        fail($file.': its header isn\'t YAML: '.$e->getMessage());
    }

    if (!is_array($header)) {
        fail($file.': its header must be "security" and "upgrade".');
    }

    $unknown = array_diff(array_keys($header), ['security', 'upgrade']);

    if ([] !== $unknown || !array_key_exists('security', $header) || !array_key_exists('upgrade', $header)) {
        fail($file.': its header must be exactly "security" (a list, [] for none) and "upgrade" (text, or null)'.([] === $unknown ? '' : '; not "'.implode('", "', $unknown).'"').'.');
    }

    if (!is_array($header['security']) || !array_is_list($header['security'])) {
        fail($file.': "security" must be a list ([] when it fixes none).');
    }

    $security = [];

    foreach ($header['security'] as $i => $advisory) {
        $where = sprintf('%s: security[%d]', $file, $i);

        if (!is_array($advisory) || [] !== array_diff(array_keys($advisory), ['id', 'severity', 'package', 'summary'])) {
            fail($where.' must have "id", "severity", "package" and "summary" only.');
        }

        foreach (['id', 'severity', 'package', 'summary'] as $key) {
            if (!is_string($advisory[$key] ?? null) || '' === trim($advisory[$key])) {
                fail($where.' needs "'.$key.'".');
            }
        }

        if (1 !== preg_match(ADVISORY, $advisory['id'])) {
            fail($where.': "'.$advisory['id'].'" isn\'t a GitHub advisory (GHSA-…) or a CVE.');
        }

        if (!in_array($advisory['severity'], SEVERITIES, true)) {
            fail($where.': its severity is one of '.implode(', ', SEVERITIES).'.');
        }

        if (1 !== preg_match('#^quire/[a-z0-9-]+$#', $advisory['package'])) {
            fail($where.': its package is one of Quire\'s (e.g. "quire/core").');
        }

        $security[] = ['id' => $advisory['id'], 'severity' => $advisory['severity'], 'package' => $advisory['package'], 'summary' => trim($advisory['summary'])];
    }

    if (null !== $header['upgrade'] && (!is_string($header['upgrade']) || '' === trim($header['upgrade']))) {
        fail($file.': "upgrade" is text, or null when there\'s nothing to do.');
    }

    $text = trim($parts[2]);

    if ('' === $text) {
        fail($file.' has no notes under its header.');
    }

    return ['security' => $security, 'upgrade' => null === $header['upgrade'] ? null : trim($header['upgrade']), 'notes' => $text];
}

/**
 * @return array{php: string, postgresql: ?int, server_contract: ?int}
 */
function requirements(string $root, string $version): array
{
    $composer = json_decode((string) at($root, $version, 'composer.json'), true);
    $php = is_array($composer) && is_string($composer['require']['php'] ?? null) ? $composer['require']['php'] : fail('v'.$version.' has no PHP requirement in composer.json.');
    $postgresql = preg_match('/image:\s*postgres:(?:\$\{POSTGRES_VERSION:-)?(\d+)/', (string) at($root, $version, 'compose.yaml'), $match) ? (int) $match[1] : null;
    // Before 0.1.1, sites kept no contract with tools that manage them.
    $contract = preg_match('/const int VERSION = (\d+);/', (string) at($root, $version, 'packages/core/src/Runtime/ServerContract.php'), $match) ? (int) $match[1] : null;

    return ['php' => $php, 'postgresql' => $postgresql, 'server_contract' => $contract];
}

/**
 * @return array<string, string> Every file of a folder, by its path, with its SHA-256
 */
function files(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $path = substr($file->getPathname(), strlen($directory) + 1);

            if ('.git' !== $path && !str_starts_with($path, '.git/')) {
                $files[$path] = (string) hash_file('sha256', $file->getPathname());
            }
        }
    }

    ksort($files, SORT_STRING);

    return $files;
}

/**
 * @return array<string, string> A published skeleton's files at its tag, with their SHA-256
 */
function published(string $repository, string $version): array
{
    $files = [];

    foreach (array_filter(explode("\0", run(['git', 'ls-tree', '-r', '-z', '--name-only', 'v'.$version.'^{commit}'], $repository))) as $path) {
        $files[$path] = hash('sha256', run(['git', 'show', 'v'.$version.'^{commit}:'.$path], $repository));
    }

    ksort($files, SORT_STRING);

    return $files;
}

/**
 * @param array<string, string> $before
 * @param array<string, string> $after
 *
 * @return list<string> The files added, changed or removed, sorted
 */
function changed(array $before, array $after): array
{
    $paths = array_keys(array_diff_assoc($after, $before) + array_diff_key($before, $after));
    sort($paths, SORT_STRING);

    return $paths;
}

$options = getopt('', ['version:', 'skeleton:', 'published:', 'root:', 'release-notes']);
$version = is_string($options['version'] ?? null) ? $options['version'] : fail('--version is needed.');
$root = is_string($options['root'] ?? null) ? $options['root'] : dirname(__DIR__);

if (1 !== preg_match(VERSION, $version)) {
    fail('"'.$version.'" isn\'t a version.');
}

if (isset($options['release-notes'])) {
    $file = $root.'/docs/releases/'.$version.'.md';
    $notes = is_file($file) ? notes($file) : fail('There are no release notes for '.$version.': write docs/releases/'.$version.'.md first.');
    $text = $notes['notes'];

    if ([] !== $notes['security']) {
        $text .= "\n\n## Security fixes\n\n".implode("\n", array_map(static fn (array $advisory): string => sprintf('- **%s** (%s, %s): %s', $advisory['id'], $advisory['severity'], $advisory['package'], $advisory['summary']), $notes['security']));
    }

    if (null !== $notes['upgrade']) {
        $text .= "\n\n## Upgrading\n\n".$notes['upgrade'];
    }

    echo $text."\n";
    exit(0);
}

$skeleton = is_string($options['skeleton'] ?? null) && is_dir($options['skeleton']) ? rtrim($options['skeleton'], '/') : fail('--skeleton must be the built skeleton\'s folder.');
$repository = is_string($options['published'] ?? null) && is_dir($options['published']) ? $options['published'] : fail('--published must be a clone of quirecms/skeleton.');

// Every release up to this one, oldest first
$versions = [];

foreach (glob($root.'/docs/releases/*.md') ?: [] as $file) {
    $name = basename($file, '.md');

    if (1 !== preg_match(VERSION, $name)) {
        fail($file.' isn\'t named after a version (e.g. 0.1.2.md).');
    }

    if (version_compare($name, $version, '<=')) {
        $versions[] = $name;
    }
}

usort($versions, version_compare(...));

if ([] === $versions || end($versions) !== $version) {
    fail('There are no release notes for '.$version.': write docs/releases/'.$version.'.md first.');
}

foreach ($versions as $release) {
    if ('' === trim(run(['git', 'tag', '--list', 'v'.$release], $root))) {
        fail('docs/releases/'.$release.'.md has no tag v'.$release.'.');
    }
}

$releases = [];
$before = null;

foreach ($versions as $release) {
    $files = $release === $version ? files($skeleton) : published($repository, $release);

    if ([] === $files) {
        fail('The skeleton of '.$release.' has no files.');
    }

    $notes = notes($root.'/docs/releases/'.$release.'.md');
    $releases[] = [
        'version' => $release,
        'date' => trim(run(['git', 'log', '-1', '--format=%cs', 'v'.$release.'^{commit}'], $root)),
        'security' => $notes['security'],
        'notes' => $notes['notes'],
        'upgrade' => $notes['upgrade'],
        'requires' => requirements($root, $release),
        'skeleton' => [
            'files' => $files,
            'changed' => null === $before ? null : changed($before, $files),
        ],
    ];
    $before = $files;
}

$feed = [
    'schema' => 1,
    'name' => 'Quire',
    'repository' => 'quirecms/framework',
    // Newest first
    'releases' => array_reverse($releases),
];

echo json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
