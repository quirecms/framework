<?php

declare(strict_types=1);

namespace Quire\Admin\Twig;

use Quire\Settings\Settings;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Markup;

/**
 * Times as people say them, in the site's time zone and language: "just now", "5 min ago", "2 h ago",
 * "today 17:00", "yesterday", "Tue 09:00" (within a week), "28 Sep", "28 Sep 2025". The exact time is in the <time>
 * element, and its title.
 *
 * @internal
 */
final readonly class RelativeTime
{
    public function __construct(
        private Settings $settings,
        private TranslatorInterface $translator,
    ) {
    }

    public function html(\DateTimeImmutable $at, ?\DateTimeImmutable $now = null): Markup
    {
        $local = $at->setTimezone($this->timezone());

        return new Markup(\sprintf('<time datetime="%s" title="%s">%s</time>', $at->format(\DATE_ATOM), $local->format('Y-m-d H:i'), htmlspecialchars($this->text($at, $now))), 'UTF-8');
    }

    public function text(\DateTimeImmutable $at, ?\DateTimeImmutable $now = null): string
    {
        $timezone = $this->timezone();
        $now = ($now ?? new \DateTimeImmutable())->setTimezone($timezone);
        $local = $at->setTimezone($timezone);
        $seconds = $now->getTimestamp() - $at->getTimestamp();
        $days = (int) $now->setTime(0, 0)->diff($local->setTime(0, 0))->format('%r%a');

        return match (true) {
            // Times stored to the second are rounded, so "now" can be a moment ahead.
            $seconds > -60 && $seconds < 60 => $this->translator->trans('just now', [], 'quire-admin'),
            $seconds >= 0 && $seconds < 3600 => $this->translator->trans('{count} min ago', ['count' => intdiv($seconds, 60)], 'quire-admin'),
            $seconds >= 0 && 0 === $days => $this->translator->trans('{count} h ago', ['count' => intdiv($seconds, 3600)], 'quire-admin'),
            // Later today, e.g. when something is scheduled.
            0 === $days => $this->translator->trans('today {time}', ['time' => $local->format('H:i')], 'quire-admin'),
            -1 === $days => $this->translator->trans('yesterday', [], 'quire-admin'),
            // Soon or lately: the weekday and the time, e.g. when something is scheduled.
            abs($days) < 7 => $this->format($local, 'EEE HH:mm'),
            $local->format('Y') === $now->format('Y') => $this->format($local, 'd MMM'),
            default => $this->format($local, 'd MMM y'),
        };
    }

    private function format(\DateTimeImmutable $at, string $pattern): string
    {
        $formatter = new \IntlDateFormatter($this->settings->getString('site.locale'), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $at->getTimezone(), null, $pattern);

        return (string) $formatter->format($at);
    }

    /**
     * The site's time zone, UTC when it has none or one PHP doesn't know.
     */
    public function timezone(): \DateTimeZone
    {
        $name = $this->settings->getString('site.timezone');

        try {
            return new \DateTimeZone('' === $name ? 'UTC' : $name);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
