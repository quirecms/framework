<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/packages/core/config',
        __DIR__.'/packages/core/src',
        __DIR__.'/packages/core/tests',
    ])
    ->withSkip([
        __DIR__.'/config/reference.php',
    ])
    ->withPhpSets()
    ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true, earlyReturn: true);
