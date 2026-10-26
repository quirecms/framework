<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Action\Exception\ActionDeniedException;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Admin\Audit\AuditEntries;
use Quire\Audit\AuditEntry;
use Quire\Audit\AuditLog;
use Quire\Audit\AuditQuery;
use Quire\Audit\AuditUndo;
use Quire\Audit\Origin;
use Quire\Settings\Settings;
use Quire\User\CurrentUser;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Activity: who did what and when, in the admin, on the command line or in background jobs, with the
 * command that does the same. Searched by words; narrowed to a person, an area, a day of the last 30
 * (bars), where it was done or dates; links from users, content and other pages narrow it to a user, a
 * subject or one request. Similar entries are folded together, what can be undone has "Undo", and the
 * whole of what's shown can be exported, as "quire:audit:list --format jsonl" does.
 *
 * @internal
 */
final readonly class AuditController
{
    public const string ABILITY = AuditLog::ABILITY;

    private const string CSRF_TOKEN = 'quire_admin_audit';

    private const int PER_PAGE = 50;

    /** How many days the bars show */
    private const int DAYS = 30;

    /** Areas to filter by, as action prefixes */
    private const array AREAS = ['content.', 'user.', 'user.signed_in', 'role.', 'setting.', 'theme.', 'extension.'];

    private const array SUBJECT_TYPES = ['content', 'user', 'role', 'setting', 'theme', 'extension'];

    /** Actions folded together when they follow each other on a day, whoever did them */
    private const array FOLDED = ['user.signed_in'];

    public function __construct(
        private AuditLog $log,
        private AuditEntries $entries,
        private AuditUndo $undo,
        private UserRepository $users,
        private CurrentUser $currentUser,
        private Settings $settings,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private CsrfTokenManagerInterface $csrf,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $this->assertAllowed();
        $timezone = $this->settings->getString('site.timezone');
        $zone = new \DateTimeZone($timezone);
        $state = $this->state($request);
        $query = $this->query($state, $zone);
        $page = $this->log->find($query, max(1, $request->query->getInt('page', 1)), self::PER_PAGE);
        $url = fn (array $changes): string => $this->urls->generate('quire_admin_audit', array_filter([...$state, 'page' => null, ...$changes], static fn (mixed $value): bool => null !== $value && '' !== $value && 1 !== $value));
        $presented = $this->entries->present($page, $timezone);
        $me = $this->currentUser->get()?->id;

        // The bars: the last 30 days, whatever days are chosen.
        $today = new \DateTimeImmutable('today', $zone);
        $first = $today->modify(\sprintf('-%d days', self::DAYS - 1));
        $byDay = $this->log->countByDay($this->between($query, $first, null), $zone);
        $days = [];

        for ($day = $first; $day <= $today; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $chosen = '' !== $state['since'] && $key >= $state['since'] && $key <= ('' === $state['until'] ? $today->format('Y-m-d') : $state['until']);
            $days[] = ['date' => $day, 'count' => $byDay[$key] ?? 0, 'chosen' => $chosen, 'url' => $chosen && $state['since'] === $state['until'] ? $url(['since' => null, 'until' => null]) : $url(['since' => $key, 'until' => $key])];
        }

        return new Response($this->twig->render('@quire_admin/audit/index.html.twig', [
            'groups' => $this->groups($presented, $zone, $byDay),
            'total' => $page->total,
            'state' => $state,
            'areas' => self::AREAS,
            'origins' => array_map(static fn (Origin $origin): string => $origin->value, Origin::cases()),
            'narrowed' => $this->narrowed($state, $presented, $url),
            'days' => $days,
            'days_total' => array_sum($byDay),
            'days_max' => max([1, ...array_values($byDay)]),
            'people' => $people = $this->log->countByActor(new AuditQuery(since: $first, exceptActions: self::FOLDED), 4),
            'me' => $me,
            'urls' => [
                'everyone' => $url(['actor' => null, 'origin' => null]),
                'me' => null === $me ? null : $url(['actor' => $me, 'origin' => null]),
                'mine' => null === $me ? null : $url(['actor' => $state['actor'] === $me ? null : $me]),
                'automatic' => $url(['actor' => null, 'origin' => Origin::Job->value]),
                'people' => array_combine(array_keys($people), array_map(static fn (string $id): string => $url(['actor' => $id, 'origin' => null]), array_keys($people))),
                'areas' => ['' => $url(['area' => null]), ...array_combine(self::AREAS, array_map(static fn (string $area): string => $url(['area' => $area]), self::AREAS))],
                'all_days' => $url(['since' => null, 'until' => null]),
                'export' => $this->urls->generate('quire_admin_audit_export', array_filter($state, static fn (string $value): bool => '' !== $value)),
            ],
            'retention' => $this->settings->getInt(AuditLog::RETENTION_SETTING),
            'page' => $page->page,
            'pages' => $page->pageCount(),
            'previous_url' => $page->hasPreviousPage() ? $url(['page' => $page->page - 1]) : null,
            'next_url' => $page->hasNextPage() ? $url(['page' => $page->page + 1]) : null,
            'back' => $request->getRequestUri(),
            'timezone' => $timezone,
        ]), $page->page > $page->pageCount() ? Response::HTTP_NOT_FOUND : Response::HTTP_OK);
    }

    /**
     * Everything the page shows, every page of it, one JSON object per line.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->assertAllowed();
        $zone = new \DateTimeZone($this->settings->getString('site.timezone'));
        // New entries would shift the pages. Times are stored rounded to the second, so a moment ahead.
        $chosen = $this->query($this->state($request), $zone);
        $query = $this->between($chosen, $chosen->since, $chosen->until ?? new \DateTimeImmutable('+1 second'));

        $response = new StreamedResponse(function () use ($query): void {
            for ($number = 1;; ++$number) {
                $page = $this->log->find($query, $number, 500);

                foreach ($page->entries as $entry) {
                    echo json_encode($entry->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), "\n";
                }

                if (!$page->hasNextPage()) {
                    return;
                }
            }
        });
        $response->headers->set('Content-Type', 'application/x-ndjson; charset=utf-8');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="activity-%s.jsonl"', new \DateTimeImmutable('now', $zone)->format('Y-m-d')));

        return $response;
    }

    /**
     * Undoes entries, e.g. those done together, the newest first; then back to the page.
     */
    public function undo(Request $request): RedirectResponse
    {
        $this->assertAllowed();

        if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('The form expired. Reload the page and try again.');
        }

        $entries = array_filter(array_map($this->log->get(...), array_values(array_filter($request->request->all('entries'), \is_string(...)))));
        usort($entries, static fn (AuditEntry $a, AuditEntry $b): int => [$b->lastAt, $b->id] <=> [$a->lastAt, $a->id]);
        [$undone, $problems] = [0, []];

        foreach ($entries as $entry) {
            try {
                $this->undo->undo($entry);
                ++$undone;
            } catch (ActionDeniedException $e) {
                throw new AccessDeniedHttpException($e->getMessage(), $e);
            } catch (InvalidArgumentsException $e) {
                $problems[] = implode(' ', $e->errors);
            } catch (\LogicException|\InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $session = $request->getSession();
        \assert($session instanceof FlashBagAwareSessionInterface);

        if ($undone > 0) {
            $session->getFlashBag()->add('success', $this->translator->trans('{count, plural, one {Undone.} other {# undone.}}', ['count' => $undone], 'quire-admin'));
        }

        if ([] !== $problems || [] === $entries) {
            $problem = [] === $problems ? $this->translator->trans('the entry is gone.', [], 'quire-admin') : implode(' ', array_unique($problems));
            $session->getFlashBag()->add('error', $this->translator->trans('It couldn’t be undone: {problem}', ['problem' => $problem], 'quire-admin'));
        }

        $back = $request->request->getString('back');
        $admin = rtrim($this->urls->generate('quire_admin_dashboard'), '/');

        return new RedirectResponse(str_starts_with($back, $admin.'/') && !str_starts_with($back, '//') ? $back : $this->urls->generate('quire_admin_audit'), Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array<string, string>
     */
    private function state(Request $request): array
    {
        $query = $request->query;
        $uuid = static fn (string $value): string => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) ? $value : '';

        return [
            'q' => mb_substr(trim($query->getString('q')), 0, 200),
            'area' => \in_array($query->getString('area'), self::AREAS, true) ? $query->getString('area') : '',
            'origin' => Origin::tryFrom($query->getString('origin'))->value ?? '',
            'since' => $this->date($query->getString('since')),
            'until' => $this->date($query->getString('until')),
            'actor' => $uuid($query->getString('actor')),
            'subject_type' => \in_array($query->getString('subject_type'), self::SUBJECT_TYPES, true) ? $query->getString('subject_type') : '',
            'subject' => mb_substr($query->getString('subject'), 0, 255),
            'correlation' => $uuid($query->getString('correlation')),
        ];
    }

    /**
     * @param array<string, string> $state
     */
    private function query(array $state, \DateTimeZone $zone): AuditQuery
    {
        return new AuditQuery(
            actorId: '' === $state['actor'] ? null : $state['actor'],
            subjectType: '' === $state['subject'] || '' === $state['subject_type'] ? null : $state['subject_type'],
            subjectId: '' === $state['subject'] ? null : $state['subject'],
            action: '' === $state['area'] ? null : $state['area'],
            origin: Origin::tryFrom($state['origin']),
            correlationId: '' === $state['correlation'] ? null : $state['correlation'],
            since: '' === $state['since'] ? null : new \DateTimeImmutable($state['since'], $zone),
            // The whole day.
            until: '' === $state['until'] ? null : new \DateTimeImmutable($state['until'], $zone)->modify('+1 day'),
            text: '' === $state['q'] ? null : $state['q'],
            textActions: '' === $state['q'] ? [] : $this->actionsSaying($state['q']),
        );
    }

    /**
     * The actions whose sentence in the admin's language says the words, e.g. "published" for
     * "content.transitioned" ("Published “…”").
     *
     * @return list<string>
     */
    private function actionsSaying(string $words): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return [];
        }

        $actions = [];

        foreach ($this->translator->getCatalogue()->all('quire-admin+intl-icu') + $this->translator->getCatalogue()->all('quire-admin') as $key => $message) {
            $key = (string) $key;

            if (str_starts_with($key, 'audit.') && 1 !== preg_match('/^audit\.(area|origin|via|detail|fold)\./', $key) && \is_string($message) && false !== mb_stripos($message, $words)) {
                $actions[] = substr($key, 6);
            }
        }

        return $actions;
    }

    /**
     * The entries by day, newest first, with similar ones that follow each other folded together: those
     * done together (e.g. publishing several items at once), and sign-ins.
     *
     * @param list<array{entry: AuditEntry, summary: string, ...}> $presented
     * @param array<string, int>                                   $byDay
     *
     * @return list<array{date: \DateTimeImmutable, count: ?int, cards: list<array{items: list<array{entry: AuditEntry, summary: string, ...}>, undo: list<string>}>}>
     */
    private function groups(array $presented, \DateTimeZone $zone, array $byDay): array
    {
        // First the cards, each one or several similar entries that follow each other on a day.
        $cards = [];

        foreach ($presented as $item) {
            $entry = $item['entry'];
            $day = $entry->lastAt->setTimezone($zone)->format('Y-m-d');
            $last = array_key_last($cards);
            $previous = null === $last ? null : $cards[$last];

            if (null !== $previous && $previous['day'] === $day && $previous['first']->action === $entry->action && ($previous['first']->correlationId === $entry->correlationId || \in_array($entry->action, self::FOLDED, true))) {
                $cards[$last]['items'][] = $item;
            } else {
                $cards[] = ['day' => $day, 'first' => $entry, 'items' => [$item]];
            }
        }

        // Then the days, and undo for a card: all of it, when each can be undone (worked out for all at once).
        $groups = [];
        $undoable = $this->undo->undoable(array_merge(...array_map(static fn (array $card): array => array_map(static fn (array $item): AuditEntry => $item['entry'], $card['items']), $cards)));

        foreach ($cards as $card) {
            $entries = array_map(static fn (array $item): AuditEntry => $item['entry'], $card['items']);
            $groups[$card['day']] ??= ['date' => new \DateTimeImmutable($card['day'], $zone), 'count' => $byDay[$card['day']] ?? null, 'cards' => []];
            $groups[$card['day']]['cards'][] = [
                'items' => $card['items'],
                'undo' => array_all($entries, static fn (AuditEntry $entry): bool => isset($undoable[$entry->id])) ? array_map(static fn (AuditEntry $entry): string => $entry->id, $entries) : [],
            ];
        }

        return array_values($groups);
    }

    /**
     * What the links narrowed the log to, each with a link that removes it.
     *
     * @param array<string, string>                                $state
     * @param list<array{entry: AuditEntry, summary: string, ...}> $presented
     * @param \Closure(array<string, mixed>): string               $url
     *
     * @return list<array{label: string, remove_url: string}>
     */
    private function narrowed(array $state, array $presented, \Closure $url): array
    {
        $narrowed = [];

        if ('' !== $state['subject']) {
            $label = array_find($presented, static fn (array $item): bool => $item['entry']->subjectId === $state['subject'])['entry']->subjectLabel ?? $state['subject'];
            $narrowed[] = ['label' => $this->translator->trans('About {name}', ['name' => $label], 'quire-admin'), 'remove_url' => $url(['subject' => null, 'subject_type' => null])];
        }

        if ('' !== $state['correlation']) {
            $narrowed[] = ['label' => $this->translator->trans('Done together', [], 'quire-admin'), 'remove_url' => $url(['correlation' => null])];
        }

        if ('' !== $state['actor'] && $state['actor'] !== $this->currentUser->get()?->id) {
            $name = $this->users->find($state['actor'])->displayName
                ?? array_find($presented, static fn (array $item): bool => $item['entry']->actorId === $state['actor'])['entry']->actorName
                ?? $state['actor'];
            $narrowed[] = ['label' => $this->translator->trans('By {name}', ['name' => $name], 'quire-admin'), 'remove_url' => $url(['actor' => null])];
        }

        return $narrowed;
    }

    /**
     * The same query over other times.
     */
    private function between(AuditQuery $query, ?\DateTimeImmutable $since, ?\DateTimeImmutable $until): AuditQuery
    {
        return new AuditQuery($query->actorId, $query->subjectType, $query->subjectId, $query->action, $query->origin, $query->correlationId, $since, $until, $query->exceptActions, $query->text, $query->textActions);
    }

    private function assertAllowed(): void
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException($this->translator->trans('Seeing the audit log takes the right to do so.', [], 'quire-admin'));
        }
    }

    private function date(string $value): string
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ? $value : '';
    }
}
