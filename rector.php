<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/packages',
    ])
    ->withSkip([
        __DIR__.'/config/reference.php',
        // Fixtures are referenced by name from tests, so "unused" code in them is deliberate.
        '*/tests/Fixtures/*',
        '*/tests/*/Fixtures/*',
        // Migrations are loaded by Doctrine, not autoloaded; leave them as written.
        '*/migrations/*',
    ])
    ->withPhpSets()
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true);
