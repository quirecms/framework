# Audit log

Quire keeps a log of who did what, and when: in the admin, on the command line, or in a background job. It's recorded by Quire itself, so a change made with `bin/console` or by a scheduled job is in it just like one made in the browser.

## What's recorded

| Area | Actions |
|------|---------|
| Content | created, edited, published, scheduled, unpublished, archived (and an extension's transitions), moved to the trash, restored, deleted permanently; changes to published items saved, sent for review, reviewed, commented on (and comments resolved or reopened), scheduled, published, discarded, and scheduled changes that couldn't be published |
| Users | added, registered, profile changed, roles changed, suspended, activated, password set or removed, invited, deleted, signed in |
| Roles and permissions | role added, renamed, deleted; abilities granted or revoked, per role |
| Settings | each setting changed, with its old and new value |
| Themes and extensions | theme changed; extensions enabled, disabled, and deleted with their data |

Each entry has:

- **who**: the signed-in user, or "command line" and "background job"; their name stays when the user is deleted,
- **where**: in the admin, on the website, the console command (e.g. `quire:user:suspend`) or the job,
- **what** it was about: the item, user, role or setting, with its name at the time,
- **details**, e.g. which fields changed, a setting's old and new value, the roles added,
- the **console command that does the same**, where there is one, e.g. `bin/console quire:content:transition 0192… publish`: what the [action](extensions.md#extendactions) that has the same effect writes, so it's the command the admin shows too. Text written in an editor, and changes of names and emails, have none,
- a **group**: everything one request, command or job did, e.g. the items of one bulk action.

Saving the same item again within 15 minutes updates its entry (with how many times, and the fields changed in any of them) instead of adding one per save, so autosaving doesn't fill the log.

### What isn't

The log is not another copy of your data:

- **no secrets**: no passwords, and for [sensitive settings](settings.md) only that they changed, never the value or a command with it,
- **no personal data beyond names**: a changed email or name is recorded as "email changed", not the new address; no IP addresses,
- **no copies of content**: entries say which fields changed; the [revisions](content.md) have the text.

## Seeing it

In the admin, "Audit log" under "Site", for users with the ability `quire.audit.view` (admins by default). It filters by area, by where (web, command line, job) and by dates; links narrow it to a user, an item, or what was done together. A user's page links to what they did, and an item's editor and history to its activity. Home shows the latest entries, without signing in.

After an action in the admin, those who may see the log also get the console commands that do the same, e.g. to put it in a deployment script. Bulk actions show the first five.

On the command line:

```bash
bin/console quire:audit:list                                  # the newest 50
bin/console quire:audit:list --user ada --since "-7 days"     # what Ada did this week
bin/console quire:audit:list --action content. --subject 0192b6a0-…
bin/console quire:audit:list --origin job
bin/console quire:audit:list --format jsonl --limit 0 > audit.jsonl   # everything, one JSON object per line
```

## Undoing

Much of what's recorded can be undone, through the action that does the opposite: publishing (through the transition back, e.g. "unpublish"), moving to the trash and restoring, suspending and letting in again, a user's roles, a role's abilities and name, settings and the theme. Only while nothing changed it since: the entry must be the latest about what it changed, and that must still be as it left it. Deletions, purges, emails, sign-ins and extensions can't be undone.

```bash
bin/console quire:audit:undo 0192b6a0-…            # the entry's ID, from quire:audit:list
bin/console quire:audit:undo 0192b6a0-… --dry-run  # only says how
```

Undoing takes the ability of the action that undoes it, and it's recorded like that action. In code, `Quire\Audit\AuditUndo` says how an entry would be undone (`plan()`), whether the signed-in person may (`canUndo()`), and does it (`undo()`).

`Quire\Audit\AuditLog` also counts entries per day (`countByDay()`, in a time zone) and per action (`countByAction()`), and `AuditQuery` finds them by text in the subject's label, the actor's name or the action.

## How long it's kept

The setting `quire.audit.retention_days` (365 by default; 0 keeps entries forever). The scheduled job `quire.prune-audit-log` deletes older entries every night, so it needs a [worker](jobs.md).

## Keeping a copy elsewhere

Anyone who can change the database can change the log. Where that matters, copy each entry somewhere it can't be changed as it's recorded: listen to `Quire\Audit\Event\AuditEntryRecorded` and send `$event->entry` to your log collector, SIEM or append-only storage.

```php
use Quire\Audit\Event\AuditEntryRecorded;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
public function __invoke(AuditEntryRecorded $event): void
{
    $this->siem->send(['action' => $event->entry->action, 'actor' => $event->entry->actorName, 'at' => $event->entry->lastAt]);
}
```

Recording never fails the action it records: when an entry can't be written, the error is logged.

## Recording an extension's actions

Inject `Quire\Audit\AuditLog` and record what your extension does, typically from a listener of its own events:

```php
$this->audit->record(
    'acme-shop.order_refunded',           // your extension's ID, a dot, a verb in the past tense
    'acme-shop.order', $order->id, 'Order '.$order->number,
    ['amount' => $refund->amount],        // never secrets
    command: 'bin/console acme-shop:refund '.$order->id,
);
```

The signed-in user, where it happened and the group are added for you. Pass `actorId` when nobody is signed in but someone is responsible, e.g. who scheduled what a job does now, and `coalesce: true` for actions repeated often, like saving.

The admin describes an entry with the translation `audit.<action>` of the domain `quire-admin`, with the parameter `subject` (the name) and one per detail; add it to your extension's `translations/` directory:

```yaml
# translations/quire-admin+intl-icu.en.yaml
'audit.acme-shop.order_refunded': 'Refunded {amount} for {subject}'
```

Without one, the admin shows the action's name.
