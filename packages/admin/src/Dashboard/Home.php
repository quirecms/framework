<?php

declare(strict_types=1);

namespace Quire\Admin\Dashboard;

use Quire\Admin\Audit\AuditEntries;
use Quire\Admin\Content\ContentLists;
use Quire\Admin\Controller\SettingsController;
use Quire\Admin\Controller\ThemesController;
use Quire\Admin\Controller\UsersController;
use Quire\Admin\Twig\RelativeTime;
use Quire\Audit\AuditLog;
use Quire\Audit\AuditQuery;
use Quire\Backup\BackupLog;
use Quire\Content\Check\CheckResults;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Revision\PendingRevisionRepository;
use Quire\Content\Revision\ReviewState;
use Quire\Content\Status;
use Quire\Jobs\JobStatus;
use Quire\Settings\Settings;
use Quire\Theme\Themes;
use Quire\User\CurrentUser;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the home page shows: a greeting with what happened since yesterday, the week (what went live and
 * what will), the latest activity, the site's health, and on a new site the first steps.
 *
 * @internal
 */
final readonly class Home
{
    /** Points on the week's thread at most */
    private const int WEEK_POINTS = 14;

    private const int LATEST = 5;

    /** Audit actions left out of "Latest" */
    private const array QUIET = ['user.signed_in'];

    public function __construct(
        private ContentLists $lists,
        private ContentRepository $content,
        private PendingRevisionRepository $pending,
        private CheckResults $checks,
        private AuditLog $audit,
        private AuditEntries $entries,
        private JobStatus $jobs,
        private BackupLog $backups,
        private Settings $settings,
        private UserRepository $users,
        private CurrentUser $currentUser,
        private Security $security,
        private RelativeTime $time,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * "Good morning, Ada", by the time of day where the site is.
     */
    public function greeting(\DateTimeImmutable $now): string
    {
        $hour = (int) $now->setTimezone($this->time->timezone())->format('G');
        $name = $this->currentUser->get()?->displayName;
        $first = null === $name ? '' : explode(' ', trim($name))[0];
        $key = match (true) {
            $hour >= 5 && $hour < 12 => 'Good morning',
            $hour >= 12 && $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        return '' === $first ? $this->translator->trans($key, [], 'quire-admin') : $this->translator->trans($key.', {name}', ['name' => $first], 'quire-admin');
    }

    /**
     * "Since yesterday: 5 items went live, Kim joined, and 3 things wait for you.".
     */
    public function summary(\DateTimeImmutable $now, int $waiting): string
    {
        $parts = [];

        if ($this->security->isGranted(AuditLog::ABILITY)) {
            $since = $now->modify('-1 day');
            $live = $this->audit->find(new AuditQuery(action: 'content.published', since: $since), perPage: 1)->total;

            if ($live > 0) {
                $parts[] = $this->translator->trans('{count, plural, one {# item went live} other {# items went live}}', ['count' => $live], 'quire-admin');
            }

            $joined = [];

            foreach (['user.registered', 'user.created'] as $action) {
                foreach ($this->audit->find(new AuditQuery(action: $action, since: $since), perPage: 10)->entries as $entry) {
                    if ($entry->subjectId !== $this->currentUser->get()?->id) {
                        $joined[] = explode(' ', $entry->subjectLabel ?? '')[0];
                    }
                }
            }

            $joined = array_values(array_filter(array_unique($joined), static fn (string $name): bool => '' !== $name));

            if ([] !== $joined) {
                $parts[] = \count($joined) <= 2
                    ? $this->translator->trans('{names} joined', ['names' => $this->list($joined)], 'quire-admin')
                    : $this->translator->trans('{count} people joined', ['count' => \count($joined)], 'quire-admin');
            }
        }

        $parts[] = $this->translator->trans('{count, plural, =0 {nothing waits for you} one {# thing waits for you} other {# things wait for you}}', ['count' => $waiting], 'quire-admin');

        return $this->translator->trans('Since yesterday: {what}.', ['what' => $this->list($parts)], 'quire-admin');
    }

    /**
     * This week, Monday to Sunday where the site is: what went live, what's scheduled, and changes
     * scheduled, each at its time.
     *
     * @return array{days: list<array{label: string, today: bool}>, now: float, points: list<array{x: float, title: string, when: string, kind: string, url: string, note: ?string, below: bool}>}
     */
    public function week(\DateTimeImmutable $now): array
    {
        $timezone = $this->time->timezone();
        $start = $now->setTimezone($timezone)->modify('monday this week')->setTime(0, 0);
        $end = $start->modify('+7 days');
        $span = (float) ($end->getTimestamp() - $start->getTimestamp());
        $points = [];

        foreach ($this->lists->listableTypes() as $type) {
            $query = fn (): \Quire\Content\Query\ContentQuery => $this->lists->query($type)->where('published_at', '>=', $start)->where('published_at', '<', $end)->orderBy('published_at')->limit(self::WEEK_POINTS);
            $scheduled = [...$query()->status(Status::SCHEDULED)->get()];
            $notes = $this->checks->forItems(array_map(static fn (Content $item): string => $item->id, $scheduled));

            foreach ([...$query()->status(Status::PUBLISHED)->get(), ...$scheduled] as $item) {
                $found = \count($notes[$item->id]->results ?? []);
                $points[] = [
                    'at' => $item->publishedAt ?? $now,
                    'title' => $item->title,
                    'kind' => Status::SCHEDULED === $item->status ? 'scheduled' : 'happened',
                    'url' => $this->urls->generate('quire_admin_content_edit', ['type' => $type->id, 'id' => $item->id]),
                    'note' => $found > 0 ? $this->translator->trans('{count} to check', ['count' => $found], 'quire-admin') : null,
                ];
            }
        }

        $scheduledChanges = $this->pending->scheduledBetween($now, $end);
        $items = $this->content->findMany(array_map(static fn (\Quire\Content\Revision\PendingRevision $changes): string => $changes->contentId, $scheduledChanges));

        foreach ($scheduledChanges as $changes) {
            $item = $items[$changes->contentId] ?? null;

            if ($item instanceof Content && null !== $changes->publishAt && \in_array($item->type, array_map(static fn (\Quire\Content\Type\ContentTypeDefinition $type): string => $type->id, $this->lists->listableTypes()), true)) {
                $review = \in_array($changes->state, [ReviewState::InReview, ReviewState::ChangesRequested], true);
                $points[] = [
                    'at' => $changes->publishAt,
                    'title' => $item->title,
                    'kind' => $review ? 'review' : 'scheduled',
                    'url' => $this->urls->generate('quire_admin_content_changes', ['type' => $item->type, 'id' => $item->id, 'pending' => $changes->id]),
                    'note' => $review ? $this->translator->trans('waiting for review', [], 'quire-admin') : $this->translator->trans('changes', [], 'quire-admin'),
                ];
            }
        }

        usort($points, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        $week = [];

        foreach (\array_slice($points, 0, self::WEEK_POINTS) as $index => $point) {
            $week[] = [
                'x' => round(100 * ($point['at']->getTimestamp() - $start->getTimestamp()) / $span, 2),
                'title' => $point['title'],
                'when' => $this->format($point['at']->setTimezone($timezone), $point['at']->setTimezone($timezone)->format('Y-m-d') === $now->setTimezone($timezone)->format('Y-m-d') ? 'HH:mm' : 'EEE HH:mm'),
                'kind' => $point['kind'],
                'url' => $point['url'],
                'note' => $point['note'],
                // Labels take turns above and below the line, so neighbours don't cover each other.
                'below' => 1 === $index % 2,
            ];
        }

        $days = [];

        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $days[] = ['label' => $this->format($day, $day->getTimestamp() === $start->getTimestamp() ? 'EEE d' : 'EEE'), 'today' => $day->format('Y-m-d') === $now->setTimezone($timezone)->format('Y-m-d')];
        }

        return ['days' => $days, 'now' => round(100 * ($now->getTimestamp() - $start->getTimestamp()) / $span, 2), 'points' => $week];
    }

    /**
     * The latest entries of the audit log, for those who may read it.
     *
     * @return list<array<string, mixed>>|null
     */
    public function latest(): ?array
    {
        if (!$this->security->isGranted(AuditLog::ABILITY)) {
            return null;
        }

        // What people did, not that they signed in.
        return $this->entries->present($this->audit->find(new AuditQuery(exceptActions: self::QUIET), perPage: self::LATEST), $this->settings->getString('site.timezone'));
    }

    /**
     * Whether the site is well, for those who run it: online, background tasks, backups.
     *
     * @return list<array{label: string, tone: string, detail?: string}>|null
     */
    public function health(): ?array
    {
        if (!$this->security->isGranted(SettingsController::ABILITY)) {
            return null;
        }

        $health = [['label' => $this->translator->trans('Online', [], 'quire-admin'), 'tone' => 'ok']];
        $report = $this->jobs->report();
        $problems = $report->problems();
        $health[] = match (true) {
            !$report->queued => ['label' => $this->translator->trans('Background tasks run right away', [], 'quire-admin'), 'tone' => 'ok'],
            [] === $problems => ['label' => $this->translator->trans('Background tasks running', [], 'quire-admin'), 'tone' => 'ok'],
            default => ['label' => $this->translator->trans('Background tasks need you', [], 'quire-admin'), 'tone' => 'danger', 'detail' => $problems[0]],
        };
        $last = $this->backups->last();
        $good = $this->backups->lastSuccessful();
        $health[] = match (true) {
            $last instanceof \Quire\Backup\BackupRun && !$last->ok => ['label' => $this->translator->trans('The last backup failed', [], 'quire-admin'), 'tone' => 'danger'],
            $good instanceof \Quire\Backup\BackupRun => ['label' => $this->translator->trans('Backed up {when}', ['when' => $this->time->text($good->finishedAt)], 'quire-admin'), 'tone' => 'ok'],
            default => ['label' => $this->translator->trans('No backups yet', [], 'quire-admin'), 'tone' => 'warning'],
        };

        return $health;
    }

    /**
     * A new site's first steps, those the user may take, while some aren't done; null once they all are,
     * or when the user may take none.
     *
     * @return array{steps: list<array{title: string, text: string, done: bool, url: ?string, action: ?string}>, done: int}|null
     */
    public function firstSteps(): ?array
    {
        $steps = [];

        if ($this->security->isGranted(SettingsController::ABILITY)) {
            $named = 'default' !== $this->settings->source('site.title');
            $steps[] = [
                'title' => $this->translator->trans('Name your site', [], 'quire-admin'),
                'text' => $named
                    ? $this->translator->trans('“{title}”, in {locale}, {timezone} time.', ['title' => $this->settings->getString('site.title'), 'locale' => \Locale::getDisplayLanguage($this->settings->getString('site.locale'), $this->translator->getLocale()), 'timezone' => $this->time->timezone()->getName()], 'quire-admin')
                    : $this->translator->trans('Its name, language and time zone.', [], 'quire-admin'),
                'done' => $named,
                'url' => $this->urls->generate('quire_admin_settings', ['group' => 'site']),
                'action' => $this->translator->trans('Name it', [], 'quire-admin'),
            ];
        }

        $types = $this->lists->listableTypes();

        if ([] !== $types) {
            $written = array_any($types, fn (\Quire\Content\Type\ContentTypeDefinition $type): bool => $this->lists->query($type, withTrashed: true)->limit(1)->count() > 0);
            $first = $types[0];
            $steps[] = [
                'title' => $this->translator->trans('Write your first post', [], 'quire-admin'),
                'text' => $this->translator->trans('A title is enough to start. It stays a draft until you publish it.', [], 'quire-admin'),
                'done' => $written,
                'url' => $this->urls->generate('quire_admin_content_new', ['type' => $first->id]),
                'action' => $this->translator->trans('Start writing', [], 'quire-admin'),
            ];
        }

        if ($this->security->isGranted(UsersController::ABILITY)) {
            $steps[] = [
                'title' => $this->translator->trans('Invite someone to help', [], 'quire-admin'),
                'text' => $this->translator->trans('They get an email to choose their own password.', [], 'quire-admin'),
                'done' => $this->users->search(limit: 1)['total'] > 1,
                'url' => $this->urls->generate('quire_admin_user_new'),
                'action' => $this->translator->trans('Invite', [], 'quire-admin'),
            ];
        }

        if ($this->security->isGranted(ThemesController::ABILITY)) {
            $steps[] = [
                'title' => $this->translator->trans('Choose a look', [], 'quire-admin'),
                'text' => $this->translator->trans('See your site in each installed theme before you switch.', [], 'quire-admin'),
                'done' => 'default' !== $this->settings->source(Themes::SETTING),
                'url' => $this->urls->generate('quire_admin_themes'),
                'action' => $this->translator->trans('Browse themes', [], 'quire-admin'),
            ];
        }

        $done = \count(array_filter($steps, static fn (array $step): bool => $step['done']));

        return [] === $steps || $done === \count($steps) ? null : ['steps' => $steps, 'done' => $done];
    }

    /**
     * Whether the site is new: nothing of it is live yet.
     */
    public function isNew(): bool
    {
        return !array_any($this->lists->listableTypes(), fn (\Quire\Content\Type\ContentTypeDefinition $type): bool => $this->lists->query($type)->status(Status::PUBLISHED, Status::SCHEDULED)->limit(1)->count() > 0);
    }

    /**
     * @param list<string> $items
     */
    private function list(array $items): string
    {
        $formatter = new \IntlListFormatter($this->translator->getLocale());

        return (string) $formatter->format($items);
    }

    private function format(\DateTimeImmutable $at, string $pattern): string
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $at->getTimezone(), null, $pattern);

        return (string) $formatter->format($at);
    }
}
