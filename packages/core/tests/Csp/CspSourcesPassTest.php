<?php

declare(strict_types=1);

namespace Quire\Tests\Csp;

use PHPUnit\Framework\TestCase;
use Quire\Csp\Internal\CspSourcesPass;
use Quire\Csp\Internal\FirstPartySourcesInterface;
use Quire\Csp\Internal\NoThirdPartySources;
use Quire\Csp\ThirdPartySourcesInterface;
use Quire\Storage\Internal\DiskSources;
use Quire\Tests\Csp\Fixtures\OutsideSources;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Condition C-C (doc 20): other sites' hosts only from quire/privacy, core's own sources only from core.
 */
final class CspSourcesPassTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testCoresDefaultAndQuirePrivacyMayFillTheSlot(): void
    {
        foreach ([NoThirdPartySources::class, 'Quire\\Privacy\\Internal\\ServiceSources'] as $class) {
            $container = new ContainerBuilder();
            $container->register('sources', $class);
            $container->setAlias(ThirdPartySourcesInterface::class, 'sources');

            new CspSourcesPass()->process($container);
        }
    }

    public function testAnExtensionCantAddHostsOfItsOwn(): void
    {
        $container = new ContainerBuilder();
        $container->register('acme', 'Acme\\Tracking\\TrackerSources');
        $container->setAlias(ThirdPartySourcesInterface::class, 'acme');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"Acme\Tracking\TrackerSources" adds other sites\' hosts to the Content-Security-Policy. Only quire/privacy may');

        new CspSourcesPass()->process($container);
    }

    public function testOnlyCoresPartsAddFirstPartySources(): void
    {
        $container = new ContainerBuilder();
        $container->register(DiskSources::class, DiskSources::class)->addTag(FirstPartySourcesInterface::TAG);
        new CspSourcesPass()->process($container);

        $container->register(OutsideSources::class, OutsideSources::class)->addTag(FirstPartySourcesInterface::TAG);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('only core\'s parts may');

        new CspSourcesPass()->process($container);
    }
}
