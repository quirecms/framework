<?php

declare(strict_types=1);

namespace Quire\Admin\Audit;

use Quire\Audit\AuditEntry;
use Quire\Audit\Origin;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Permission\Permissions;
use Quire\Settings\SettingsSchema;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Audit entries as people read them: a sentence (the translation "audit.<action>" of the domain
 * "quire-admin", which extensions add for their actions), links to who and what, and the details.
 *
 * @internal
 */
final class AuditEntries implements ResetInterface
{
    /** Details the sentence or the links say already */
    private const array HIDDEN_DETAILS = ['type', 'locale', 'status', 'pending_revision', 'version', 'firewall', 'transition', 'sensitive', 'verdict'];

    /** Details in this order; others after them, by name (PostgreSQL keeps JSON objects' keys in an order of its own) */
    private const array DETAIL_ORDER = ['changed', 'from', 'to', 'added', 'removed', 'granted', 'revoked', 'roles', 'at', 'reason', 'reassigned_to'];

    /** @var array<string, User|null> */
    private array $users = [];

    /** @var array<string, Content|null> */
    private array $content = [];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ContentRepository $contentRepository,
        private readonly Permissions $permissions,
        private readonly SettingsSchema $settings,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param iterable<AuditEntry> $entries
     *
     * @return list<array{entry: AuditEntry, summary: string, subject_url: ?string, actor: string, actor_url: ?string, via: string, details: list<array{label: string, value: string}>}>
     */
    public function present(iterable $entries, string $timezone): array
    {
        $entries = array_values([...$entries]);
        $presented = [];
        $this->load($entries);

        foreach ($entries as $entry) {
            $presented[] = [
                'entry' => $entry,
                'summary' => $this->summary($entry),
                'subject_url' => $this->subjectUrl($entry),
                'actor' => $entry->actorName ?? $this->translator->trans('audit.origin.'.$entry->origin->value, [], 'quire-admin'),
                'actor_url' => null !== $entry->actorId && $this->user($entry->actorId) instanceof User ? $this->urls->generate('quire_admin_user', ['id' => $entry->actorId]) : null,
                'via' => $this->via($entry),
                'details' => $this->details($entry, $timezone),
            ];
        }

        return $presented;
    }

    public function summary(AuditEntry $entry): string
    {
        $key = 'audit.'.$entry->action;
        $parameters = ['subject' => $entry->subjectLabel ?? $entry->subjectId ?? ''];

        foreach ($entry->details as $name => $value) {
            $parameters[$name] = match (true) {
                \is_array($value) && array_is_list($value) => implode(', ', array_map($this->text(...), $value)),
                default => $this->text($value),
            };
        }

        $summary = $this->translator->trans($key, $parameters, 'quire-admin');

        // An extension's action it doesn't describe.
        return $summary === $key ? (null === $entry->subjectType ? $entry->action : \sprintf('%s: %s', $entry->action, $parameters['subject'])) : $summary;
    }

    /**
     * Where the subject can be seen now; null when it's gone, or has no page.
     */
    public function subjectUrl(AuditEntry $entry): ?string
    {
        $id = $entry->subjectId;

        if (null === $id) {
            return null;
        }

        return match ($entry->subjectType) {
            'content' => $this->item($id) instanceof Content ? $this->urls->generate('quire_admin_content_edit', ['type' => $this->item($id)->type, 'id' => $id]) : null,
            'user' => $this->user($id) instanceof User ? $this->urls->generate('quire_admin_user', ['id' => $id]) : null,
            'role' => $this->permissions->hasRole($id) ? $this->urls->generate('quire_admin_role', ['role' => $id]) : null,
            'setting' => $this->settings->has($id) ? $this->urls->generate('quire_admin_settings', ['group' => $this->settings->get($id)->group ?? $this->settings->get($id)->owner]) : null,
            'theme' => $this->urls->generate('quire_admin_themes'),
            'extension' => $this->urls->generate('quire_admin_extensions'),
            default => null,
        };
    }

    private function via(AuditEntry $entry): string
    {
        $via = $entry->via;

        return match ($entry->origin) {
            Origin::Web => null !== $via && str_starts_with($via, 'quire_admin') ? $this->translator->trans('audit.via.admin', [], 'quire-admin') : $this->translator->trans('audit.via.website', [], 'quire-admin'),
            Origin::Console => $via ?? '',
            Origin::Job => $this->translator->trans('audit.via.job', ['job' => null === $via ? '' : substr($via, (int) strrpos('\\'.$via, '\\'))], 'quire-admin'),
        };
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function details(AuditEntry $entry, string $timezone): array
    {
        $details = [];

        if (true === ($entry->details['sensitive'] ?? null)) {
            $details[] = ['label' => $this->translator->trans('audit.detail.value', [], 'quire-admin'), 'value' => $this->translator->trans('Not recorded: the setting is sensitive.', [], 'quire-admin')];
        }

        $order = array_flip(self::DETAIL_ORDER);
        $names = array_keys($entry->details);
        usort($names, static fn (string $a, string $b): int => [$order[$a] ?? \PHP_INT_MAX, $a] <=> [$order[$b] ?? \PHP_INT_MAX, $b]);

        foreach ($names as $name) {
            $value = $entry->details[$name];

            if (\in_array($name, self::HIDDEN_DETAILS, true) || [] === $value) {
                continue;
            }

            $key = 'audit.detail.'.$name;
            $label = $this->translator->trans($key, [], 'quire-admin');
            $details[] = [
                'label' => $label === $key ? $name : $label,
                'value' => 'at' === $name && \is_string($value) ? $this->time($value, $timezone) : $this->value($value),
            ];
        }

        return $details;
    }

    private function value(mixed $value): string
    {
        return \is_array($value) && array_is_list($value) && [] === array_filter($value, static fn (mixed $item): bool => !\is_scalar($item))
            ? implode(', ', array_map($this->text(...), $value))
            : $this->text($value);
    }

    private function text(mixed $value): string
    {
        return match (true) {
            null === $value => '—',
            true === $value => 'true',
            false === $value => 'false',
            \is_scalar($value) => (string) $value,
            default => (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        };
    }

    private function time(string $value, string $timezone): string
    {
        try {
            return new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d H:i');
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * The people and items the entries are by and about, all at once.
     *
     * @param list<AuditEntry> $entries
     */
    private function load(array $entries): void
    {
        $users = array_filter([...array_map(static fn (AuditEntry $entry): ?string => $entry->actorId, $entries), ...array_map(static fn (AuditEntry $entry): ?string => 'user' === $entry->subjectType ? $entry->subjectId : null, $entries)], \is_string(...));
        $items = array_filter(array_map(static fn (AuditEntry $entry): ?string => 'content' === $entry->subjectType ? $entry->subjectId : null, $entries), \is_string(...));
        $missing = array_values(array_diff(array_unique($users), array_keys($this->users)));
        $found = $this->userRepository->findMany($missing);

        foreach ($missing as $id) {
            $this->users[$id] = $found[$id] ?? null;
        }

        $missing = array_values(array_diff(array_unique($items), array_keys($this->content)));
        $found = $this->contentRepository->findMany($missing);

        foreach ($missing as $id) {
            $this->content[$id] = $found[$id] ?? null;
        }
    }

    public function reset(): void
    {
        $this->users = [];
        $this->content = [];
    }

    private function user(string $id): ?User
    {
        if (!\array_key_exists($id, $this->users)) {
            $this->users[$id] = $this->userRepository->find($id);
        }

        return $this->users[$id];
    }

    private function item(string $id): ?Content
    {
        if (!\array_key_exists($id, $this->content)) {
            $this->content[$id] = $this->contentRepository->find($id);
        }

        return $this->content[$id];
    }
}
