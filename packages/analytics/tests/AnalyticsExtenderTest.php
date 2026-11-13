<?php

declare(strict_types=1);

namespace Quire\Analytics\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quire\Analytics\Extend\Analytics;
use Quire\Extend\ExtenderContext;
use Quire\Extend\ExtenderException;
use Quire\Privacy\Services;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The site declares Google Analytics 4 with its measurement ID: a service of quire/privacy, in
 * "Analytics", with Google's hosts. Never a theme or an extension, which many sites share.
 */
final class AnalyticsExtenderTest extends TestCase
{
    public function testTheSiteDeclaresGoogleAnalyticsAsAServiceVisitorsAreAskedAbout(): void
    {
        $container = new ContainerBuilder();
        new Analytics()->ga4('G-ABC123DEF4', keepParameters: ['q'])->extend($this->context($container));

        $declaration = $this->declarations($container)[0];
        self::assertSame(Analytics::GA4, $declaration['id']);
        self::assertSame('statistics', $declaration['category']);
        self::assertSame('Google Ireland Limited', $declaration['vendor']);
        self::assertStringContainsString('United States (Google LLC)', $declaration['purpose']);
        self::assertSame(['_ga', '_ga_*'], array_column($declaration['cookies'], 0));
        self::assertSame(['https://*.googletagmanager.com'], $declaration['hosts']['script-src']);
        self::assertSame('quire-analytics/ga4.js', $declaration['script']);
        self::assertSame(['measurement_id' => 'G-ABC123DEF4', 'keep' => 'q'], $declaration['config']);
        self::assertSame(ExtenderContext::SITE, $declaration['owner']);
    }

    public function testWithoutAnIdNothingIsDeclared(): void
    {
        $container = new ContainerBuilder();
        new Analytics()->extend($this->context($container));

        self::assertSame([], $this->declarations($container));
    }

    public function testAThemeCantSetTheId(): void
    {
        $this->expectException(ExtenderException::class);
        $this->expectExceptionMessageIsOrContains('Only the site\'s own extend.php sets the Google Analytics measurement ID');

        new Analytics()->ga4('G-ABC123DEF4')->extend(new ExtenderContext('acme-theme', '/tmp', new ContainerBuilder(), ['quire-privacy', 'quire-analytics'], theme: true));
    }

    public function testNorAnExtension(): void
    {
        $this->expectException(ExtenderException::class);

        new Analytics()->ga4('G-ABC123DEF4')->extend(new ExtenderContext('acme-stats', '/tmp', new ContainerBuilder(), ['quire-privacy', 'quire-analytics']));
    }

    public function testQuireAnalyticsMustBeEnabled(): void
    {
        $this->expectException(ExtenderException::class);
        $this->expectExceptionMessageIsOrContains('Enable quire/analytics first');

        new Analytics()->ga4('G-ABC123DEF4')->extend(new ExtenderContext(ExtenderContext::SITE, '/tmp', new ContainerBuilder(), ['quire-privacy']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnId(): iterable
    {
        yield 'Universal Analytics' => ['UA-12345-1'];
        yield 'lower case' => ['g-abc123def4'];
        yield 'too short' => ['G-AB1'];
        yield 'a tag of Tag Manager' => ['GTM-ABC123'];
        yield 'with a script' => ['G-ABC123"><script>'];
    }

    #[DataProvider('notAnId')]
    public function testTheIdIsCheckedAtOnce(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('isn\'t a Google Analytics 4 measurement ID');

        new Analytics()->ga4($id);
    }

    public function testKeptParametersAreNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Analytics()->ga4('G-ABC123DEF4', keepParameters: ['q,token']);
    }

    private function context(ContainerBuilder $container): ExtenderContext
    {
        return new ExtenderContext(ExtenderContext::SITE, '/tmp', $container, ['quire-privacy', 'quire-analytics']);
    }

    /**
     * @return list<array{id: string, category: string, vendor: string, purpose: string, cookies: list<array{string, string, string}>, hosts: array<string, list<string>>, script: ?string, config: array<string, scalar>, owner: string}>
     */
    private function declarations(ContainerBuilder $container): array
    {
        /** @var list<array{id: string, category: string, vendor: string, purpose: string, cookies: list<array{string, string, string}>, hosts: array<string, list<string>>, script: ?string, config: array<string, scalar>, owner: string}> $declarations */
        $declarations = $container->getParameterBag()->all()[Services::PARAMETER] ?? [];

        return $declarations;
    }
}
