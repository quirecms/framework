<?php

declare(strict_types=1);

namespace Quire\Admin\Intent;

use Quire\Action\Actions;
use Quire\Action\Exception\InvalidArgumentsException;
use Quire\Admin\Content\ContentLists;
use Quire\Admin\Site\SettingsForm;
use Quire\Admin\Twig\RelativeTime;
use Quire\Content\Content;
use Quire\Content\Workflow\ContentWorkflow;
use Quire\Permission\Permissions;
use Quire\Settings\SettingDefinition;
use Quire\Settings\Settings;
use Quire\Settings\SettingsSchema;
use Quire\Settings\SettingType;
use Quire\Theme\Themes;
use Quire\User\Role;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Understands what people type to the intent bar, in a small fixed grammar: a verb, what it's about, and
 * maybe a time. "publish spring draw", "schedule venue guide friday 18:00", "unpublish results",
 * "trash old news", "restore old news", "suspend kim", "let kim in again", "invite ana@example.org as
 * editor", "use the theme default", "set site title to Tournament News". Each becomes an action of the
 * catalogue, so what it does, who may, and its command are the action's.
 *
 * It names things the site has: an item by its title, a person by their name, username or email, a
 * setting by its label or key. When it doesn't understand, the bar finds instead.
 *
 * @internal
 */
final readonly class IntentParser
{
    /** The verbs, in English and German, by what they do */
    private const array VERBS = [
        'unpublish' => '/^(?:unpublish|take (?:down|off(?: the site)?)|depubliziere(?:n)?|zurückziehen|ziehe zurück)\s+/u',
        'publish' => '/^(?:publish|veröffentliche(?:n)?)\s+/u',
        'schedule' => '/^(?:schedule|plane(?:n)?)\s+/u',
        'trash' => '/^(?:trash|delete|remove|move to (?:the )?trash|lösche(?:n)?)\s+/u',
        'restore' => '/^(?:restore|wiederherstellen|stelle wieder her)\s+/u',
        'activate' => '/^(?:activate|unsuspend|let in(?: again)?|entsperre(?:n)?)\s+/u',
        'suspend' => '/^(?:suspend|block|sperre(?:n)?)\s+/u',
        'invite' => '/^(?:invite|lade(?:n)?(?: ein)?|einladen)\s+/u',
        'theme' => '/^(?:use (?:the )?theme|switch (?:the )?theme to|use the look|nutze (?:das )?theme)\s+/u',
        'set' => '/^(?:set|change|setze(?:n)?|ändere(?:n)?)\s+/u',
    ];

    /** Words around a name that aren't part of it */
    private const array FILLERS = ['/^(?:the|a|an|my|das|den|die|der|post|page|item|beitrag|seite)\s+/u', '/\s+(?:in again|again|wieder|now|jetzt)$/u'];

    public function __construct(
        private ContentLists $lists,
        private ContentWorkflow $workflow,
        private UserRepository $users,
        private Permissions $permissions,
        private Themes $themes,
        private SettingsSchema $schema,
        private Settings $settings,
        private SettingsForm $settingsForm,
        private Actions $actions,
        private RelativeTime $time,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function parse(string $text, ?\DateTimeImmutable $now = null): ?Intent
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        // "let kim in again" names the person in the middle.
        $text = (string) preg_replace('/^let\s+(.+?)\s+in(?:\s+again)?$/iu', 'let in $1', $text);
        $lower = mb_strtolower($text);
        $verb = null;

        foreach (self::VERBS as $name => $pattern) {
            if (1 === preg_match($pattern, $lower.' ', $match)) {
                $verb = $name;
                $text = trim(mb_substr($text, mb_strlen(rtrim($match[0]))));

                break;
            }
        }

        return match ($verb) {
            null => null,
            'publish', 'unpublish', 'schedule', 'trash', 'restore' => $this->content($verb, $text, $now ?? new \DateTimeImmutable()),
            'suspend', 'activate' => $this->person($verb, $text),
            'invite' => $this->invite($text),
            'theme' => $this->theme($text),
            default => $this->setting($text),
        };
    }

    private function content(string $verb, string $text, \DateTimeImmutable $now): ?Intent
    {
        [$at, $rest] = TimeWords::extract(mb_strtolower($text), $this->time->timezone(), $now);
        $item = $this->item($this->name($rest), withTrashed: 'restore' === $verb);

        if (!$item instanceof Content) {
            return null;
        }

        // "publish … friday 18:00" schedules it.
        $verb = 'publish' === $verb && null !== $at ? 'schedule' : $verb;
        $title = '“'.$item->title.'”';
        [$action, $arguments, $step] = match ($verb) {
            'trash' => ['content.trash', ['content' => $item->id], $this->trans('{title} moves to the trash; you can restore it from there.', ['title' => $title])],
            'restore' => ['content.restore', ['content' => $item->id], $this->trans('{title} comes back from the trash.', ['title' => $title])],
            'unpublish' => ['content.transition', ['content' => $item->id, 'transition' => 'unpublish'], $this->trans('{title} is taken off the site: only people who edit can see it.', ['title' => $title])],
            'publish' => ['content.transition', ['content' => $item->id, 'transition' => 'publish'], $this->trans('{title} goes live now, for everyone.', ['title' => $title])],
            default => ['content.transition', ['content' => $item->id, 'transition' => 'schedule', ...null === $at ? [] : ['at' => $at->format(\DATE_ATOM)]], null === $at ? '' : $this->trans('{title} goes live by itself on {when}.', ['title' => $title, 'when' => $this->when($at)])],
        };
        $denied = match (true) {
            'schedule' === $verb && null === $at => $this->trans('Say when, e.g. “friday 18:00” or “tomorrow at 9”.'),
            'schedule' === $verb && $at <= $now => $this->trans('That time has passed.'),
            'content.transition' === $action && !\in_array($arguments['transition'] ?? null, $this->workflow->enabledTransitions($item), true) => $this->trans('{title} can’t be {verb} as it is now ({status}).', ['title' => $title, 'verb' => $this->trans('intent.past.'.$verb), 'status' => $this->lists->statusLabel($item->status)]),
            'trash' === $verb && $item->isTrashed() => $this->trans('{title} is in the trash already.', ['title' => $title]),
            'restore' === $verb && !$item->isTrashed() => $this->trans('{title} isn’t in the trash.', ['title' => $title]),
            default => null,
        };
        $parts = [['label' => $this->trans('intent.verb.'.$verb), 'kind' => 'verb'], ['label' => $item->title, 'kind' => 'item', 'status' => $item->status]];

        if ('schedule' === $verb && null !== $at) {
            $parts[] = ['label' => $this->when($at).' · '.$this->time->timezone()->getName(), 'kind' => 'time'];
        }

        return $this->intent($action, $arguments, $parts, [$step], $denied, $this->urls->generate('quire_admin_content_edit', ['type' => $item->type, 'id' => $item->id]));
    }

    private function person(string $verb, string $text): ?Intent
    {
        $user = $this->user($this->name($text));

        if (!$user instanceof User) {
            return null;
        }

        $suspend = 'suspend' === $verb;

        return $this->intent(
            $suspend ? 'user.suspend' : 'user.activate',
            ['user' => $user->id],
            [['label' => $this->trans('intent.verb.'.$verb), 'kind' => 'verb'], ['label' => $user->displayName, 'kind' => 'person']],
            [$suspend
                ? $this->trans('{name} can’t sign in any more, and is signed out on their next request.', ['name' => $user->displayName])
                : $this->trans('{name} can sign in again.', ['name' => $user->displayName])],
            match (true) {
                $suspend && !$user->isActive() => $this->trans('{name} is suspended already.', ['name' => $user->displayName]),
                !$suspend && $user->isActive() => $this->trans('{name} can sign in already.', ['name' => $user->displayName]),
                default => null,
            },
            $this->urls->generate('quire_admin_user', ['id' => $user->id]),
        );
    }

    private function invite(string $text): ?Intent
    {
        preg_match_all('/[^\s,;]+@[^\s,;]+\.[^\s,;]+/u', $text, $emails);

        if ([] === $emails[0]) {
            return null;
        }

        $role = null;

        if (1 === preg_match('/\b(?:as|als)\s+(?:an?\s+|ein(?:e|en)?\s+)?(\S+)/u', mb_strtolower($text), $match)) {
            $wanted = rtrim($match[1], 's');

            foreach ($this->permissions->roles() as $name => $label) {
                if (Role::GUEST !== $name && \in_array($wanted, [mb_strtolower($name), rtrim(mb_strtolower($label), 's')], true)) {
                    $role = $name;
                }
            }
        }

        $parts = [['label' => $this->trans('intent.verb.invite'), 'kind' => 'verb'], ...array_map(static fn (string $email): array => ['label' => $email, 'kind' => 'person'], $emails[0])];

        if (null !== $role) {
            $parts[] = ['label' => $this->trans('as {role}', ['role' => $this->permissions->roles()[$role]]), 'kind' => 'role'];
        }

        return $this->intent(
            'user.invite',
            ['users' => $emails[0], ...null === $role ? [] : ['roles' => [$role]]],
            $parts,
            [$this->trans('{emails} get an email to choose their own password; the link works once, for 7 days.', ['emails' => implode(', ', $emails[0])])],
            null,
            $this->urls->generate('quire_admin_users'),
        );
    }

    private function theme(string $text): ?Intent
    {
        $wanted = $this->name(mb_strtolower($text));
        $theme = array_find($this->themes->installed(), static fn ($manifest): bool => \in_array($wanted, [mb_strtolower($manifest->title), $manifest->id], true));

        if (null === $theme) {
            return null;
        }

        return $this->intent(
            'theme.use',
            ['theme' => $theme->id],
            [['label' => $this->trans('intent.verb.theme'), 'kind' => 'verb'], ['label' => $theme->title, 'kind' => 'theme']],
            [$this->trans('The site looks like the theme {theme}; it’s rebuilt, which takes a few seconds.', ['theme' => $theme->title])],
            $theme->id === $this->themes->active()?->id ? $this->trans('The site uses {theme} already.', ['theme' => $theme->title]) : null,
            $this->urls->generate('quire_admin_themes'),
        );
    }

    private function setting(string $text): ?Intent
    {
        if (1 !== preg_match('/^(?<name>.+?)\s+(?:to|auf|=)\s+(?<value>.+)$/u', $text, $match)) {
            return null;
        }

        $wanted = $this->name(mb_strtolower($match['name']));
        $definition = $this->schema->has($wanted) ? $this->schema->get($wanted) : array_find($this->schema->all(), fn (SettingDefinition $definition): bool => null !== $definition->label && $wanted === mb_strtolower($this->translator->trans($definition->label, [], $definition->owner)));

        if (null === $definition || $definition->sensitive) {
            return null;
        }

        $value = $this->value($definition, trim($match['value'], " \"'“”"));
        $label = null === $definition->label ? $definition->key : $this->translator->trans($definition->label, [], $definition->owner);

        return $this->intent(
            'setting.set',
            ['setting' => $definition->key, 'value' => $value],
            [['label' => $this->trans('intent.verb.set'), 'kind' => 'verb'], ['label' => $label, 'kind' => 'setting'], ['label' => $this->settingsForm->shown($definition, $value, short: true), 'kind' => 'value']],
            [$this->trans('{setting}: {from} → {to}.', ['setting' => $label, 'from' => $this->settingsForm->shown($definition, $this->settings->get($definition->key), short: true), 'to' => $this->settingsForm->shown($definition, $value, short: true)])],
            match (true) {
                'environment' === $this->settings->source($definition->key) => $this->trans('It’s set by the server’s environment.'),
                null !== $definition->validate($value) => $this->trans('That doesn’t fit the setting: {problem}.', ['problem' => $definition->validate($value)]),
                default => null,
            },
            $this->urls->generate('quire_admin_settings'),
        );
    }

    /**
     * A value as the setting's type takes it: "on" for a switch, a choice by its label, a number.
     */
    private function value(SettingDefinition $definition, string $text): mixed
    {
        $lower = mb_strtolower($text);

        return match ($definition->type) {
            SettingType::Bool => match (true) {
                \in_array($lower, ['on', 'yes', 'true', 'an', 'ja'], true) => true,
                \in_array($lower, ['off', 'no', 'false', 'aus', 'nein'], true) => false,
                default => $text,
            },
            SettingType::Int => is_numeric($text) ? (int) $text : $text,
            SettingType::Float => is_numeric($text) ? (float) $text : $text,
            SettingType::Enum => array_find($definition->choices, fn (string|int $choice): bool => mb_strtolower((string) $choice) === $lower || mb_strtolower($this->settingsForm->shown($definition, $choice)) === $lower) ?? $text,
            default => $text,
        };
    }

    /**
     * The item whose title the words say best: the same title, else the one search ranks first.
     */
    private function item(string $words, bool $withTrashed): ?Content
    {
        if ('' === $words) {
            return null;
        }

        $best = null;
        $score = 0.0;

        foreach ($this->lists->listableTypes() as $type) {
            foreach ($this->lists->query($type, $withTrashed)->search($words, prefix: true)->limit(5)->get() as $item) {
                similar_text(mb_strtolower($item->title), $words, $percent);
                $percent += mb_strtolower($item->title) === $words ? 100 : 0;

                if ($percent > $score) {
                    [$best, $score] = [$item, $percent];
                }
            }
        }

        return $best;
    }

    private function user(string $words): ?User
    {
        if ('' === $words) {
            return null;
        }

        return $this->users->findByReference($words) ?? ($this->users->search($words, limit: 1)['users'][0] ?? null);
    }

    /**
     * The name in the words, without "the", "post" and the like.
     */
    private function name(string $words): string
    {
        return trim((string) preg_replace(self::FILLERS, '', mb_strtolower(trim($words, " \"'“”.!?"))));
    }

    private function when(\DateTimeImmutable $at): string
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $this->time->timezone(), null, 'EEE d MMM, HH:mm');

        return (string) $formatter->format($at);
    }

    /**
     * @param array<string, mixed>                          $arguments
     * @param list<array{label: string, kind: string, ...}> $parts
     * @param list<string>                                  $steps
     */
    private function intent(string $action, array $arguments, array $parts, array $steps, ?string $denied, ?string $change): Intent
    {
        try {
            $denied ??= $this->actions->denied($action, $arguments);
            $command = $this->actions->commandLine($action, $arguments);
        } catch (InvalidArgumentsException $e) {
            $denied ??= implode(' ', $e->errors);
            $command = null;
        }

        return new Intent($action, $arguments, $parts, array_values(array_filter($steps, static fn (string $step): bool => '' !== $step)), $command, $denied, $change);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, 'quire-admin');
    }
}
