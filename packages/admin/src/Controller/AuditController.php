<?php

declare(strict_types=1);

namespace Quire\Admin\Controller;

use Quire\Admin\Audit\AuditEntries;
use Quire\Audit\AuditLog;
use Quire\Audit\AuditQuery;
use Quire\Audit\Origin;
use Quire\Settings\Settings;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The audit log: who did what and when, in the admin, on the command line or in background jobs, with
 * the command that does the same. Filtered by area, origin and dates; links from users, content and
 * other pages narrow it to a user (actor), a subject, or one request (correlation).
 *
 * @internal
 */
final readonly class AuditController
{
    public const string ABILITY = AuditLog::ABILITY;

    private const int PER_PAGE = 50;

    /** Areas to filter by, as action prefixes */
    private const array AREAS = ['content.', 'user.', 'user.signed_in', 'role.', 'setting.', 'theme.', 'extension.'];

    private const array SUBJECT_TYPES = ['content', 'user', 'role', 'setting', 'theme', 'extension'];

    public function __construct(
        private AuditLog $log,
        private AuditEntries $entries,
        private UserRepository $users,
        private Settings $settings,
        private Security $security,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->security->isGranted(self::ABILITY)) {
            throw new AccessDeniedHttpException($this->translator->trans('Seeing the audit log takes the right to do so.', [], 'quire-admin'));
        }

        $timezone = $this->settings->getString('site.timezone');
        $query = $request->query;
        $uuid = static fn (string $value): string => 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) ? $value : '';
        $state = [
            'area' => \in_array($query->getString('area'), self::AREAS, true) ? $query->getString('area') : '',
            'origin' => Origin::tryFrom($query->getString('origin'))->value ?? '',
            'since' => $this->date($query->getString('since')),
            'until' => $this->date($query->getString('until')),
            'actor' => $uuid($query->getString('actor')),
            'subject_type' => \in_array($query->getString('subject_type'), self::SUBJECT_TYPES, true) ? $query->getString('subject_type') : '',
            'subject' => mb_substr($query->getString('subject'), 0, 255),
            'correlation' => $uuid($query->getString('correlation')),
        ];
        $zone = new \DateTimeZone($timezone);
        $page = $this->log->find(new AuditQuery(
            actorId: '' === $state['actor'] ? null : $state['actor'],
            subjectType: '' === $state['subject'] || '' === $state['subject_type'] ? null : $state['subject_type'],
            subjectId: '' === $state['subject'] ? null : $state['subject'],
            action: '' === $state['area'] ? null : $state['area'],
            origin: Origin::tryFrom($state['origin']),
            correlationId: '' === $state['correlation'] ? null : $state['correlation'],
            since: '' === $state['since'] ? null : new \DateTimeImmutable($state['since'], $zone),
            // The whole day.
            until: '' === $state['until'] ? null : new \DateTimeImmutable($state['until'], $zone)->modify('+1 day'),
        ), max(1, $query->getInt('page', 1)), self::PER_PAGE);
        $url = fn (array $changes): string => $this->urls->generate('quire_admin_audit', array_filter([...$state, 'page' => null, ...$changes], static fn (mixed $value): bool => null !== $value && '' !== $value && 1 !== $value));
        $presented = $this->entries->present($page, $timezone);

        return new Response($this->twig->render('@quire_admin/audit/index.html.twig', [
            'entries' => $presented,
            'total' => $page->total,
            'state' => $state,
            'areas' => self::AREAS,
            'origins' => array_map(static fn (Origin $origin): string => $origin->value, Origin::cases()),
            'narrowed' => $this->narrowed($state, $presented, $url),
            'page' => $page->page,
            'pages' => $page->pageCount(),
            'previous_url' => $page->hasPreviousPage() ? $url(['page' => $page->page - 1]) : null,
            'next_url' => $page->hasNextPage() ? $url(['page' => $page->page + 1]) : null,
            'timezone' => $timezone,
        ]), $page->page > $page->pageCount() ? Response::HTTP_NOT_FOUND : Response::HTTP_OK);
    }

    /**
     * What the links narrowed the log to, each with a link that removes it.
     *
     * @param array<string, string>                                             $state
     * @param list<array{entry: \Quire\Audit\AuditEntry, summary: string, ...}> $presented
     * @param \Closure(array<string, mixed>): string                            $url
     *
     * @return list<array{label: string, remove_url: string}>
     */
    private function narrowed(array $state, array $presented, \Closure $url): array
    {
        $narrowed = [];

        if ('' !== $state['actor']) {
            $name = $this->users->find($state['actor'])->displayName
                ?? array_find($presented, static fn (array $item): bool => $item['entry']->actorId === $state['actor'])['entry']->actorName
                ?? $state['actor'];
            $narrowed[] = ['label' => $this->translator->trans('By {name}', ['name' => $name], 'quire-admin'), 'remove_url' => $url(['actor' => null])];
        }

        if ('' !== $state['subject']) {
            $label = array_find($presented, static fn (array $item): bool => $item['entry']->subjectId === $state['subject'])['entry']->subjectLabel ?? $state['subject'];
            $narrowed[] = ['label' => $this->translator->trans('About {name}', ['name' => $label], 'quire-admin'), 'remove_url' => $url(['subject' => null, 'subject_type' => null])];
        }

        if ('' !== $state['correlation']) {
            $narrowed[] = ['label' => $this->translator->trans('Done together', [], 'quire-admin'), 'remove_url' => $url(['correlation' => null])];
        }

        return $narrowed;
    }

    private function date(string $value): string
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && false !== \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ? $value : '';
    }
}
