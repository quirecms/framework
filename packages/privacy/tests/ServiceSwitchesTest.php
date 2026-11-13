<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use PHPUnit\Framework\TestCase;
use Quire\Privacy\Category;
use Quire\Privacy\Internal\ServiceSwitchesPass;
use Quire\Privacy\Services;
use Quire\Privacy\ServiceSwitchInterface;
use Quire\Privacy\Tests\Fixtures\Elsewhere\ElsewhereSwitch;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * A declared service with a switch runs only while its own package's switch turns it on, with the
 * values its declaration leaves to then. Anything else leaves it off, and the build refuses a switch
 * from another package or a second one.
 */
final class ServiceSwitchesTest extends TestCase
{
    public function testOffWithoutItsSwitch(): void
    {
        $services = new Services([$this->declaration()]);

        self::assertNull($services->get('acme-stats'));
        self::assertFalse($services->needConsent(), 'No banner.');
        self::assertSame([], $services->hosts(), 'No hosts in the policy.');
        self::assertSame(new Services([])->revision(), $services->revision(), 'Visitors aren\'t asked again about nothing.');
        self::assertSame(['acme-stats'], array_map(static fn (\Quire\Privacy\Service $service): string => $service->id, $services->declared()), 'It can still run: templates mustn\'t load it.');
    }

    public function testOnWithItsSwitchAndItsValues(): void
    {
        $services = new Services([$this->declaration()], [$this->switch(true, ['id' => 'G-1'])]);

        self::assertSame(['region' => 'eu', 'id' => 'G-1'], $services->get('acme-stats')?->config);
        self::assertSame(['https://stats.example'], $services->hosts()['script-src'] ?? null);
        self::assertTrue($services->needConsent());
    }

    public function testOffWhenItsSwitchSaysSo(): void
    {
        self::assertNull(new Services([$this->declaration()], [$this->switch(false, ['id' => 'G-1'])])->get('acme-stats'));
    }

    public function testASwitchThatFailsLeavesItOffAndSaysSo(): void
    {
        $logger = new BufferingLogger();
        $services = new Services([$this->declaration()], [$this->switch(new \RuntimeException('The settings can\'t be read.'), [])], $logger);

        self::assertNull($services->get('acme-stats'), 'Never on with values it can\'t vouch for.');
        self::assertSame([], $services->hosts());
        self::assertSame(['warning'], array_column($logger->cleanLogs(), 0));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notItsKeys(): iterable
    {
        yield 'a key not named as runtime' => [['id' => 'G-1', 'script' => 'evil.js']];
        yield 'a build-time key' => [['region' => 'us']];
        yield 'not a scalar' => [['id' => ['G-1']]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notItsKeys')]
    public function testValuesItsDeclarationDoesntNameLeaveItOff(array $config): void
    {
        $logger = new BufferingLogger();

        self::assertNull(new Services([$this->declaration()], [$this->switch(true, $config)], $logger)->get('acme-stats'));
        self::assertSame(['warning'], array_column($logger->cleanLogs(), 0));
    }

    public function testASwitchFromAnotherPackageIsIgnoredAndRefusedAtBuild(): void
    {
        $logger = new BufferingLogger();
        $services = new Services([$this->declaration(path: __DIR__.'/Fixtures/DeclaredHere')], [new ElsewhereSwitch()], $logger);

        self::assertNull($services->get('acme-stats'));
        /** @var list<array{string, string, array{problem?: string}}> $logs */
        $logs = $logger->cleanLogs();
        self::assertStringContainsString('which another package declared', $logs[0][2]['problem'] ?? '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('which another package declared: only the package that declares a service may switch it.');

        $this->build([$this->declaration(path: __DIR__.'/Fixtures/DeclaredHere')], [ElsewhereSwitch::class]);
    }

    public function testASecondSwitchIsRefusedAtBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('which has a switch already');

        $this->build([$this->declaration(path: __DIR__.'/Fixtures/Elsewhere')], [ElsewhereSwitch::class, ElsewhereSwitch::class]);
    }

    public function testASwitchOfAServiceWithoutOneIsRefusedAtBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('which isn\'t declared with a switch');

        $this->build([[...$this->declaration(path: __DIR__.'/Fixtures/Elsewhere'), 'switched' => false, 'runtime' => []]], [ElsewhereSwitch::class]);
    }

    public function testAServiceDeclaredTwiceIsRefusedAtBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('is declared twice');

        $this->build([$this->declaration(), $this->declaration()], []);
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testItsOwnPackagesSwitchBuilds(): void
    {
        $this->build([$this->declaration(path: __DIR__.'/Fixtures/Elsewhere')], [ElsewhereSwitch::class]);
    }

    /**
     * @param list<array<string, mixed>> $declarations
     * @param list<class-string>         $switches
     */
    private function build(array $declarations, array $switches): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(Services::PARAMETER, $declarations);

        foreach ($switches as $index => $class) {
            $container->register('switch.'.$index, $class)->addTag(ServiceSwitchInterface::TAG);
        }

        new ServiceSwitchesPass()->process($container);
    }

    /**
     * @return array{id: string, label: string, category: string, vendor: string, purpose: string, cookies: list<array{string, string, string}>, hosts: array<string, list<string>>, retention: ?string, policy_url: ?string, script: ?string, config: array<string, scalar>, owner: string, switched: bool, runtime: list<string>, path: string}
     */
    private function declaration(string $path = __DIR__): array
    {
        return [
            'id' => 'acme-stats', 'label' => 'Acme Stats', 'category' => Category::Statistics->value, 'vendor' => 'Acme', 'purpose' => 'Counts.',
            'cookies' => [], 'hosts' => ['script-src' => ['https://stats.example']], 'retention' => null, 'policy_url' => null, 'script' => 'acme/stats.js',
            'config' => ['region' => 'eu'], 'owner' => 'acme', 'switched' => true, 'runtime' => ['id'], 'path' => $path,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function switch(bool|\Throwable $enabled, array $config): ServiceSwitchInterface
    {
        return new readonly class($enabled, $config) implements ServiceSwitchInterface {
            /**
             * @param array<string, mixed> $config
             */
            public function __construct(private bool|\Throwable $enabled, private array $config)
            {
            }

            public static function service(): string
            {
                return 'acme-stats';
            }

            public function enabled(): bool
            {
                if ($this->enabled instanceof \Throwable) {
                    throw $this->enabled;
                }

                return $this->enabled;
            }

            public function config(): array
            {
                return $this->config;
            }
        };
    }
}
