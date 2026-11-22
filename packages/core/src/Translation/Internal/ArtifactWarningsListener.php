<?php

declare(strict_types=1);

namespace Quire\Translation\Internal;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Says which language packages are left out, and why, when the site is built from the command line
 * (cache:clear, cache:warmup: a deployment, an update): an ignored one never stops the site, so it's said
 * where whoever builds it looks. quire:about lists them too.
 *
 * @internal
 */
final readonly class ArtifactWarningsListener
{
    private const array BUILDS = ['cache:clear', 'cache:warmup'];

    /**
     * @param list<array{name: string, status: string, reason: ?string}> $artifacts
     */
    public function __construct(
        #[Autowire(param: TranslationArtifactsPass::ARTIFACTS)]
        private array $artifacts = [],
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function __invoke(ConsoleTerminateEvent $event): void
    {
        if (!\in_array($event->getCommand()?->getName(), self::BUILDS, true) || 0 !== $event->getExitCode()) {
            return;
        }

        $io = new SymfonyStyle($event->getInput(), $event->getOutput());

        foreach ($this->artifacts as $artifact) {
            if ('loaded' !== $artifact['status']) {
                $io->warning(\sprintf('The language package "%s" isn\'t loaded: %s', $artifact['name'], $artifact['reason']));
            }
        }
    }
}
