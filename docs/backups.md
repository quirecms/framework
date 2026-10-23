# Backups

Everything a Quire site stores is in PostgreSQL, in the `quire` schema (see [database](database.md)), except the bytes of uploaded files, which are on [storage disks](storage.md). One backup of that schema holds content, revisions, settings, redirects, which extensions are enabled, the data of every extension, the record of every stored file, and the migration history.

> **Uploaded files aren't in the backup yet.** Back up local storage disks yourself (the `storage_data` volume, or `storage/`), and turn on versioning or replication for buckets. A later release adds them to `quire:backup`.

## Making a backup

```bash
bin/console quire:backup                      # writes var/backups/quire-<date>-<time>.dump
bin/console quire:backup /backups/site.dump   # writes the given file
bin/console quire:backup - > site.dump        # writes to standard output
```

With Docker, pass `-T` when writing to standard output, so no terminal settings mangle the file:

```bash
docker compose exec -T php bin/console quire:backup - > site.dump
```

A backup is a `pg_dump` archive in PostgreSQL's custom format (compressed). It's consistent even while the site is being used, and PostgreSQL's own tools can read it too, e.g. `pg_restore --list site.dump`.

- **Only Quire's data.** When Quire shares a database with an application, the application's tables aren't in the backup; back them up separately.
- **Safe to write.** A file written by `quire:backup` appears only once the backup is complete, so a failed backup never leaves a truncated file behind, and only its owner can read it. (Writing to standard output, that's up to whatever receives it.) It contains all of the site's data, including anything private: store it as carefully as the database itself.
- **Client tools.** The commands run `pg_dump` and `pg_restore`, which must be at least the version of the PostgreSQL server. The Docker image includes them. Elsewhere, install the client tools, e.g. the `postgresql-client-18` package from [PostgreSQL's repository](https://www.postgresql.org/download/).

## Restoring a backup

```bash
bin/console quire:restore site.dump --dry-run   # shows what's in it and whether it can be restored
bin/console quire:restore site.dump
gunzip -c site.dump.gz | bin/console quire:restore -
```

Restoring replaces all of Quire's data with the backup's. When the database already has Quire's data, the command asks first; pass `--force` to skip the question, e.g. in scripts. Nothing outside the `quire` schema is touched.

After the data is restored, the application is rebuilt for the extensions the backup has enabled, the migrations the backup doesn't have yet run, and the PHP workers restart. It's all or nothing: until all of that has succeeded, the data it replaces is kept aside, and if anything fails it's put back.

While a restore runs, which takes seconds for most sites, pages that read from the database fail. Restore when few people use the site.

### Which code a backup needs

A backup needs code at least as new as the code that made it. `quire:restore` checks before changing anything, and refuses when:

- the backup has an extension enabled that isn't installed: install it with Composer first;
- the backup has migrations that Quire or one of its extensions doesn't have, because it was made with a newer version: update to that version first.

Older backups are fine: their missing migrations run after the restore. So to move a site to a new server, deploy the same code (or newer) there, then restore.

It's also refused when tables or views of an application in the same database depend on Quire's tables (e.g. a foreign key to `quire.content`), because they'd be dropped along with Quire's tables. Drop them before restoring and create them again afterwards.

## Scheduling backups

Run `quire:backup` from cron or a systemd timer, and copy the files somewhere other than the server. For example, a script that makes a backup next to the Docker setup and keeps two weeks of them:

```bash
#!/bin/sh
set -eu
cd /srv/site
docker compose exec -T php bin/console quire:backup - > /srv/backups/site.partial
mv /srv/backups/site.partial "/srv/backups/site-$(date +%F).dump"
find /srv/backups -name 'site-*.dump' -mtime +14 -delete
```

The command fails when the backup does, so the `mv` only keeps complete backups.

A backup you've never restored is only a hope: restore one into a test environment now and then (`--dry-run` alone doesn't prove the data is complete).

Managed PostgreSQL services usually offer point-in-time recovery of the whole database. That includes Quire's data, and complements `quire:backup`: it can go back to any moment, while `quire:backup` files are portable to any server and contain Quire's data alone.
