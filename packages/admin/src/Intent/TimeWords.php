<?php

declare(strict_types=1);

namespace Quire\Admin\Intent;

/**
 * Finds a time in words, as people type it to the intent bar: "friday 18:00", "tomorrow at 9",
 * "today 17:30", "in 2 hours", "on 2026-12-24 09:00", "30 oct 18:00". In the site's time zone; a day
 * without a time is at 09:00, a weekday is the next one.
 *
 * @internal
 */
final class TimeWords
{
    private const array WEEKDAYS = [
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
        'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        'montag' => 1, 'dienstag' => 2, 'mittwoch' => 3, 'donnerstag' => 4, 'freitag' => 5, 'samstag' => 6, 'sonntag' => 7,
    ];

    private const array MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        'mär' => 3, 'mai' => 5, 'okt' => 10, 'dez' => 12,
    ];

    /**
     * The time the text names, and the text without it.
     *
     * @return array{0: ?\DateTimeImmutable, 1: string}
     */
    public static function extract(string $text, \DateTimeZone $zone, \DateTimeImmutable $now): array
    {
        $now = $now->setTimezone($zone);
        $clock = '(?:(?:at|um)\s+)?(?<hour>\d{1,2})(?::(?<minute>\d{2}))?\s*(?<half>am|pm)?(?:\s*uhr)?';
        $patterns = [
            // "in 2 hours", "in 3 days"
            '/\b(?:in)\s+(?<amount>\d+)\s+(?<unit>minutes?|hours?|days?|weeks?|minuten?|stunden?|tagen?|wochen?)\b/u' => 'relative',
            // "2026-12-24 09:00"
            '/\b(?:on\s+|am\s+)?(?<date>\d{4}-\d{2}-\d{2})(?:[ t]+'.$clock.')?\b/u' => 'date',
            // "30 oct 18:00", "30. okt"
            '/\b(?:on\s+|am\s+)?(?<day>\d{1,2})\.?\s+(?<month>[a-zä]{3})[a-zä]*\.?(?:\s+'.$clock.')?\b/u' => 'month',
            // "today 17:30", "tomorrow at 9", "friday 18:00"
            '/\b(?:on\s+|next\s+|am\s+|nächsten?\s+)?(?<word>today|tonight|tomorrow|heute|morgen|'.implode('|', array_keys(self::WEEKDAYS)).')\b(?:\s+'.$clock.')?/u' => 'word',
            // "at 18:00" alone: today, or tomorrow when it's past
            '/\b(?:at|um)\s+(?<hour>\d{1,2})(?::(?<minute>\d{2}))?\s*(?<half>am|pm)?\b/u' => 'clock',
        ];

        foreach ($patterns as $pattern => $kind) {
            if (1 !== preg_match($pattern, $text, $match)) {
                continue;
            }

            $time = self::time($kind, $match, $now);

            if ($time instanceof \DateTimeImmutable) {
                return [$time, trim((string) preg_replace('/\s+/u', ' ', str_replace($match[0], ' ', $text)))];
            }
        }

        return [null, $text];
    }

    /**
     * @param array<int|string, string> $match
     */
    private static function time(string $kind, array $match, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $at = static function (\DateTimeImmutable $day, int $default = 9) use ($match): ?\DateTimeImmutable {
            $hour = '' === ($match['hour'] ?? '') ? $default : (int) $match['hour'];
            $hour += 'pm' === ($match['half'] ?? '') && $hour < 12 ? 12 : 0;
            $hour -= 'am' === ($match['half'] ?? '') && 12 === $hour ? 12 : 0;
            $minute = '' === ($match['minute'] ?? '') ? 0 : (int) $match['minute'];

            return $hour > 23 || $minute > 59 ? null : $day->setTime($hour, $minute);
        };

        return match ($kind) {
            'relative' => $now->modify(\sprintf('+%d %s', (int) $match['amount'], match (mb_substr($match['unit'], 0, 3)) {
                'min' => 'minutes', 'hou', 'stu' => 'hours', 'day', 'tag' => 'days', default => 'weeks',
            })),
            'date' => false === ($day = \DateTimeImmutable::createFromFormat('!Y-m-d', $match['date'], $now->getTimezone())) ? null : $at($day),
            'month' => isset(self::MONTHS[$match['month']]) && checkdate(self::MONTHS[$match['month']], (int) $match['day'], (int) $now->format('Y'))
                ? self::upcoming($at($now->setDate((int) $now->format('Y'), self::MONTHS[$match['month']], (int) $match['day'])), $now, '+1 year')
                : null,
            'word' => match ($word = $match['word']) {
                'today', 'heute' => $at($now),
                'tonight' => $at($now, 20),
                'tomorrow', 'morgen' => $at($now->modify('+1 day')),
                default => $at($now->modify(((int) $now->format('N')) === self::WEEKDAYS[$word] ? '+7 days' : 'next '.array_search(self::WEEKDAYS[$word], \array_slice(self::WEEKDAYS, 0, 7), true))),
            },
            default => self::upcoming($at($now), $now, '+1 day'),
        };
    }

    /**
     * The time, or the next one like it when it's past.
     */
    private static function upcoming(?\DateTimeImmutable $time, \DateTimeImmutable $now, string $next): ?\DateTimeImmutable
    {
        return $time instanceof \DateTimeImmutable ? $time <= $now ? $time->modify($next) : $time : (null);
    }
}
