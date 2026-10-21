<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

new Dotenv()->bootEnv(__DIR__.'/../.env');

if (filter_var($_SERVER['APP_DEBUG'] ?? false, \FILTER_VALIDATE_BOOL)) {
    umask(0000);
}
