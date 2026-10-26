<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard;

use Quire\Admin\Extend\Admin;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Service\ResetInterface;

/**
 * What needs the current user, from every provider they may ask, most pressing first. Asked once per
 * request: the menu shows the count on every page, and Home the items.
 *
 * @internal
 */
final class Inbox implements ResetInterface
{
    /** @var list<InboxItem>|null */
    private ?array $items = null;

    /**
     * @param list<array{class: class-string<InboxProviderInterface>, ability: ?string, owner: string}> $providers
     * @param ServiceLocator<InboxProviderInterface>                                                    $services
     */
    public function __construct(
        #[Autowire(param: Admin::INBOX_PARAMETER)]
        private readonly array $providers,
        #[AutowireLocator(Admin::INBOX_TAG)]
        private readonly ServiceLocator $services,
        private readonly Security $security,
    ) {
    }

    /**
     * @return list<InboxItem>
     */
    public function items(): array
    {
        if (null !== $this->items) {
            return $this->items;
        }

        $items = [];

        if ($this->security->getUser() instanceof \Symfony\Component\Security\Core\User\UserInterface) {
            foreach ($this->providers as $provider) {
                if (null === $provider['ability'] || $this->security->isGranted($provider['ability'])) {
                    foreach ($this->services->get($provider['class'])->items() as $item) {
                        $items[$item->key] = $item;
                    }
                }
            }
        }

        $items = array_values($items);
        usort($items, static fn (InboxItem $a, InboxItem $b): int => [$b->priority, $a->at] <=> [$a->priority, $b->at]);

        return $this->items = $items;
    }

    public function count(): int
    {
        return \count($this->items());
    }

    public function reset(): void
    {
        $this->items = null;
    }
}
