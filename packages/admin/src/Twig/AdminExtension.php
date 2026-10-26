<?php

declare(strict_types=1);

namespace Quire\Admin\Twig;

use Quire\Admin\Content\ContentLists;
use Quire\Admin\Dashboard\Inbox;
use Quire\Admin\Dashboard\InboxItem;
use Quire\Admin\Navigation\AdminMenu;
use Quire\Admin\Navigation\MenuItem;
use Quire\Admin\Preferences\Density;
use Quire\Admin\Preferences\UnderTheHood;
use Quire\Content\Type\ContentTypeDefinition;
use Quire\Settings\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;
use Twig\Markup;

/**
 * Twig functions of the admin's templates.
 *
 * @internal
 */
final readonly class AdminExtension
{
    public function __construct(
        private AdminMenu $menu,
        private ContentLists $contentLists,
        private UnderTheHood $hood,
        private Density $density,
        private RelativeTime $time,
        private Settings $settings,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private Inbox $inbox,
    ) {
    }

    /**
     * How many things wait for the current user, for the count beside Home.
     */
    #[AsTwigFunction(name: 'quire_admin_inbox_count')]
    public function inboxCount(): int
    {
        return $this->inbox->count();
    }

    /**
     * Text with what it's about marked with **…** (see {@see InboxItem}): escaped, the marked parts bold.
     */
    #[AsTwigFilter(name: 'quire_admin_marked')]
    public function marked(string $text): Markup
    {
        $html = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return new Markup((string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html), 'UTF-8');
    }

    /**
     * @return array<string, list<MenuItem>>
     */
    #[AsTwigFunction(name: 'quire_admin_menu')]
    public function menu(): array
    {
        return $this->menu->sections();
    }

    /**
     * What the current user may create, for the "New" menu.
     *
     * @return list<array{label: string, url: string}>
     */
    #[AsTwigFunction(name: 'quire_admin_new')]
    public function new(): array
    {
        return array_values(array_map(
            fn (ContentTypeDefinition $type): array => ['label' => $type->label, 'url' => $this->urls->generate('quire_admin_content_new', ['type' => $type->id])],
            array_filter($this->contentLists->listableTypes(), fn (ContentTypeDefinition $type): bool => $this->security->isGranted('content.'.$type->id.'.create')),
        ));
    }

    #[AsTwigFunction(name: 'quire_admin_hood')]
    public function hood(): bool
    {
        return $this->hood->isOn();
    }

    /**
     * "comfortable" or "compact", for the page's data-density.
     */
    #[AsTwigFunction(name: 'quire_admin_density')]
    public function density(): string
    {
        return $this->density->get();
    }

    /**
     * A number as the site's language writes it, e.g. "2,140" or "2.140".
     */
    #[AsTwigFilter(name: 'quire_admin_number')]
    public function number(int|float $number): string
    {
        return (string) new \NumberFormatter($this->settings->getString('site.locale'), \NumberFormatter::DECIMAL)->format($number);
    }

    /**
     * A date in the site's language, by an ICU pattern, e.g. "LLLL y" for "October 2026"; in the date's
     * own time zone.
     */
    #[AsTwigFilter(name: 'quire_admin_date')]
    public function date(\DateTimeImmutable $at, string $pattern): string
    {
        return (string) new \IntlDateFormatter($this->settings->getString('site.locale'), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $at->getTimezone(), null, $pattern)->format($at);
    }

    /**
     * A time as people say it, e.g. "2 h ago" or "Tue 09:00", in a <time> with the exact time.
     */
    #[AsTwigFilter(name: 'quire_admin_when')]
    public function when(\DateTimeImmutable $at): Markup
    {
        return $this->time->html($at);
    }
}
