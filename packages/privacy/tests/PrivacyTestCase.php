<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Testing\ExtensionTestCase;
use Quire\Testing\TestInstallation;

/**
 * The package with the fixture extension "quire-fixture-video", whose services are a video
 * (marketing) and a font service (preferences), in the default theme.
 */
abstract class PrivacyTestCase extends ExtensionTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-privacy', 'quire-fixture-video'];

    #[\Override]
    protected static ?string $theme = 'quire-theme-default';

    #[\Override]
    protected static function installedJson(): string
    {
        return TestInstallation::projectDir().'/tests/Fixtures/installed.json';
    }
}
