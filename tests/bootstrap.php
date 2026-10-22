<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

if (filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL)) {
    umask(0000);
}

// Bring the test database up to date, so "composer test" works on a fresh checkout.
foreach (['doctrine:database:create --if-not-exists', 'doctrine:migrations:migrate --no-interaction --allow-no-migration', 'quire:migrations:migrate --no-interaction --allow-no-migration'] as $command) {
    passthru(sprintf('%s %s --env=test --quiet %s', escapeshellarg(\PHP_BINARY), escapeshellarg(__DIR__.'/../bin/console'), $command), $exitCode);

    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('Preparing the test database failed: "bin/console %s" exited with code %d.', $command, $exitCode));
    }
}
