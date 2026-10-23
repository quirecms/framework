# Backups

A backup holds a whole site: everything in PostgreSQL's `quire` schema (see [database](database.md)), i.e. content, revisions, settings, redirects, which extensions are enabled, the data of every extension and the migration history, and the uploaded files on [storage disks](storage.md).

## Making a backup

```bash
bin/console quire:backup                      # writes var/backups/quire-<date>-<time>.tar
bin/console quire:backup /backups/site.tar    # writes the given file
bin/console quire:backup - > site.tar         # writes to standard output
```

With Docker, pass `-T` when writing to standard output, so no terminal settings mangle the file:

```bash
docker compose exec -T php bin/console quire:backup - > site.tar
```

A backup is a tar archive, which any tar program reads (`tar -tvf site.tar`), of:

- `database.dump`: the database, as a `pg_dump` archive in PostgreSQL's custom format (compressed), which PostgreSQL's own tools read too;
- `files/<disk>/<key>`: the stored files;
- `manifest.json`: what was copied, and what was missing.

It's consistent even while the site is being used: the files are exactly those the dumped database lists (read from the same snapshot), and [cleaning up stored files](storage.md#cleaning-up) waits while a backup runs.

### Which files

```bash
bin/console quire:backup                          # files on local disks (the default)
bin/console quire:backup --files=all              # files on S3 disks too
bin/console quire:backup --files=none             # the database only
bin/console quire:backup --files-dir=/backups/files
```

- **Local disks** are copied by default: nothing else has those files. Files on **S3 disks** aren't, as buckets keep their own copies: turn on versioning or replication for them. Pass `--files=all` to copy them anyway, e.g. for a copy kept away from the provider, or to move the site to another one.
- **Every file is checked** against its checksum as it's copied. Files that are missing from their disk, or damaged, are listed in a warning and in the manifest: the site already lacks them, so the backup can't have a good copy.
- **A files directory** (`--files-dir`) keeps the files outside the backup, named after their content as on the disks. Stored files never change, so each backup copies only the files that are new, and daily backups of a large media library stay small and fast. Keep the directory with the backups, and give it to `quire:restore` with the same option. With Docker, it must be a directory the container sees, e.g. a volume mounted at `/backups`.

- **Only Quire's data.** When Quire shares a database with an application, the application's tables aren't in the backup; back them up separately.
- **Safe to write.** A file written by `quire:backup` appears only once the backup is complete, so a failed backup never leaves a truncated file behind, and only its owner can read it. (Writing to standard output, that's up to whatever receives it.) It contains all of the site's data, including anything private: store it as carefully as the database itself.
- **Client tools.** The commands run `pg_dump` and `pg_restore`, which must be at least the version of the PostgreSQL server. The Docker image includes them. Elsewhere, install the client tools, e.g. the `postgresql-client-18` package from [PostgreSQL's repository](https://www.postgresql.org/download/).

## Restoring a backup

```bash
bin/console quire:restore site.tar --dry-run   # shows what's in it and whether it can be restored
bin/console quire:restore site.tar
gunzip -c site.tar.gz | bin/console quire:restore -
bin/console quire:restore site.tar --files-dir=/backups/files   # for backups made with --files-dir
```

Backups made before files were included (`.dump` files) are restored too.

Restoring replaces all of Quire's data with the backup's. When the database already has Quire's data, the command asks first; pass `--force` to skip the question, e.g. in scripts. Nothing outside the `quire` schema is touched.

After the data is restored, the application is rebuilt for the extensions the backup has enabled, the migrations the backup doesn't have yet run, and the PHP workers restart. It's all or nothing: until all of that has succeeded, the data it replaces is kept aside, and if anything fails it's put back.

**Stored files go to the site's disks, wherever they are now.** A backup made while the files were on a local disk fills the S3 bucket when the disk has moved there since: a backup and a restore is also how to move files from one place to another. Each file is checked against its checksum first, so a damaged copy is never stored, and files the disk already has are left alone. Afterwards, every file the restored database lists is checked to be on its disk, and missing ones are listed in a warning. Files are written before the database is replaced, and files a failed restore wrote are [cleaned up](storage.md#cleaning-up) later.

While a restore runs, which takes seconds for most sites, pages that read from the database fail. Restore when few people use the site.

### Which code a backup needs

A backup needs code at least as new as the code that made it. `quire:restore` checks before changing anything, and refuses when:

- the backup has an extension enabled that isn't installed: install it with Composer first;
- the backup has migrations that Quire or one of its extensions doesn't have, because it was made with a newer version: update to that version first.

Older backups are fine: their missing migrations run after the restore. So to move a site to a new server, deploy the same code (or newer) there, then restore.

It's refused, too, when the backup has files on a disk the site won't have: install the extension that declares it, or configure it in `quire.storage.disks`.

It's also refused when tables or views of an application in the same database depend on Quire's tables (e.g. a foreign key to `quire.content`), because they'd be dropped along with Quire's tables. Drop them before restoring and create them again afterwards.

## Scheduling backups

Run `quire:backup` from cron or a systemd timer, and copy the files somewhere other than the server. For example, a script that makes a backup next to the Docker setup and keeps two weeks of them:

```bash
#!/bin/sh
set -eu
cd /srv/site
docker compose exec -T php bin/console quire:backup - > /srv/backups/site.partial
mv /srv/backups/site.partial "/srv/backups/site-$(date +%F).tar"
find /srv/backups -name 'site-*.tar' -mtime +14 -delete
```

The command fails when the backup does, so the `mv` only keeps complete backups.

A backup you've never restored is only a hope: restore one into a test environment now and then (`--dry-run` alone doesn't prove the data is complete).

Run [`quire:storage:gc`](storage.md#cleaning-up) with a grace period at least as long as you keep backups that use a files directory or S3 versions, so the files an older database refers to are still there.

Managed PostgreSQL services usually offer point-in-time recovery of the whole database. That includes Quire's data, and complements `quire:backup`: it can go back to any moment, while `quire:backup` files are portable to any server and contain Quire's data alone, with the files.
