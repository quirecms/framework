# Background jobs

Some work shouldn't make a visitor wait, or must happen at a set time: sending emails, publishing scheduled content, cleaning up unused files, and in extensions resizing images, calling webhooks or updating a search index. Quire runs such work as **jobs**, in the background, with [Symfony Messenger](https://symfony.com/doc/current/messenger.html).

By default jobs wait in PostgreSQL, in the table `quire.job`, until a **worker** runs them. No message broker is needed. The Docker setup includes a worker (the `worker` service), so jobs run with no setup.

```bash
docker compose exec php bin/console quire:jobs:status
```

## How jobs run

- **Jobs are saved with the change that caused them.** A job dispatched while content is being saved is written in the same database transaction. If the change is rolled back, the job is too; if it's committed, the job is there. No change goes without its job, and no job runs for a change that never happened. (A separate queue, such as Redis, can't promise that.)
- **Failed jobs are retried**, 5 times over about 20 minutes (after 10 seconds, then 30 seconds, 1.5, 4.5 and 13.5 minutes), so a mail server or an API that's down for a while doesn't lose anything. After that, they're kept in the transport `quire_failed`:

  ```bash
  bin/console messenger:failed:show --transport=quire_failed
  bin/console messenger:failed:retry --transport=quire_failed
  ```

- **Emails are jobs.** Emails Quire sends, e.g. to reset a password, are sent by the worker, and retried when the mail server is unavailable (`config/packages/messenger.yaml`).
- **Workers restart themselves when extensions change.** A worker checks every 15 seconds whether the enabled extensions or the theme changed, e.g. on another server. When they did, it rebuilds the application and stops, and Docker starts it again with the new extensions' code.

Run more workers when jobs wait too long: `docker compose up -d --scale worker=3`. Each job runs once, whichever worker takes it.

## Scheduled tasks

Core and extensions declare tasks that run on a schedule:

| Task | When | What it does |
|------|------|--------------|
| `quire.publish-due` | Every minute | Publishes scheduled items whose date has passed, so what extensions do on publishing (feeds, notifications, …) happens. Visitors see the items at their date anyway. |
| `quire.collect-garbage` | Daily at 03:30 | Deletes stored files that nothing has used for `quire.storage.gc_grace_days` days (default 1), like [`quire:storage:gc`](storage.md#cleaning-up) |
| `quire.prune-audit-log` | Daily at 03:45 | Deletes [audit log](audit.md) entries older than `quire.audit.retention_days` days (default 365) |

Workers dispatch them: every worker checks the schedule every 15 seconds, and each occurrence runs **once**, however many workers and servers there are. That needs no lock and no shared cache: each task has a row in `quire.job_schedule`, and the worker that moves it forward runs the occurrence, in the same transaction. When no worker ran at the time, e.g. during a deployment, the most recent missed occurrence runs when one starts again, and older ones are skipped. A task appearing for the first time, e.g. from a newly enabled extension, starts with its next occurrence.

`quire:jobs:status` lists the tasks, when they last ran and when they run next.

## Without a worker

Small sites can run jobs at once instead, in the request or command that dispatches them, with no worker:

```dotenv
QUIRE_JOBS_DSN=sync://
```

Then nothing dispatches the scheduled tasks; run them every minute from cron:

```
* * * * * cd /path/to/site && php bin/console quire:jobs:run-schedule
```

Running it on several servers is safe. With `sync://`, slow jobs make requests slow, and failed jobs aren't retried: use a worker when you can.

`QUIRE_JOBS_DSN` takes any Messenger transport, e.g. `redis://…` to keep jobs in Redis. The default, `quire://`, is PostgreSQL. Its workers poll the table, an indexed query a second while idle, rather than wait for PostgreSQL's notifications, which don't work behind connection poolers such as PgBouncer. On direct connections, `quire://?notify=1` picks up jobs without the poll.

## Monitoring

`quire:jobs:status` shows what waits, which workers run and when the scheduled tasks ran, and warns about what needs attention:

- jobs waiting for over 5 minutes with no worker running (or for over 30 minutes: the workers don't keep up);
- nothing has run the schedule for 10 minutes;
- jobs that failed after their retries.

With `--check`, it exits with an error in those cases, for monitoring. Workers record a heartbeat in `quire.job_worker` every 15 seconds, and touch `var/quire-worker.heartbeat`, which the `worker` service's Docker health check reads. A long job delays the heartbeat, so workers count as gone only after 10 minutes without one.

Jobs aren't part of the readiness check (`/_quire/health`): a backlog of jobs is no reason for a load balancer to stop sending visitors to a server.

## Jobs of your own

A job is a message class implementing `Quire\Jobs\Job`, and a Messenger handler for it. Keep messages small: IDs rather than objects, since the item may have changed by the time the job runs.

```php
use Quire\Jobs\Job;

final readonly class GenerateVariants implements Job
{
    public function __construct(public string $objectId) {}
}
```

```php
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateVariantsHandler
{
    public function __invoke(GenerateVariants $job): void
    {
        // …
    }
}
```

Dispatch it on the message bus (`Symfony\Component\Messenger\MessageBusInterface`):

```php
$bus->dispatch(new GenerateVariants($object->id));
```

To dispatch jobs for content changes, listen to the "before" events, such as `ContentCreating`: they're dispatched inside the change's transaction, so the job is saved with the change. A job dispatched from an "after" event, such as `ContentCreated`, is dispatched after the commit, and would be lost if the process stopped in between.

In extensions, handlers loaded with `Extend\Services` are registered by their attribute. Register others, and schedule tasks, with `Extend\Jobs` (see [extensions](extensions.md#extendjobs)). In tests, `ExtensionTestCase::runJobs()` runs the jobs waiting in the queue.
