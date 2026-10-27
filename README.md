<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/quirecms/.github/main/brand/quire-wordmark-white.png">
    <img src="https://raw.githubusercontent.com/quirecms/.github/main/brand/quire-wordmark-navy.png" alt="Quire" width="320">
  </picture>
</p>

<p align="center"><strong>The extensible CMS for PHP, built on Symfony.</strong> By <a href="https://quirecms.com">YorvoLabs</a>.</p>

Quire has a small core, and everything else is an extension: the admin panel, single sign-on, comments, reviews. You write content in Markdown, and PostgreSQL is the source of truth for everything, with uploaded files on a volume or any S3-compatible service. Quire runs on its own or inside an existing Symfony application.

> **Status:** early development, no stable release yet. Content, URLs, extensions, themes, backups, users, permissions and background jobs work; the admin panel is being built, and single sign-on comes next.

## Requirements

- Docker with Docker Compose v2.10 or later

To run without Docker you need PHP 8.5 (with the `intl`, `opcache`, `apcu`, `zip` and `pdo_pgsql` extensions), Composer, and PostgreSQL 18, plus PostgreSQL's client tools (`pg_dump`, `pg_restore`) for backups.

## Quick start

A new site starts from the starter project [`quire/skeleton`](https://github.com/quirecms/skeleton), which has the core, the default theme and the admin interface:

```bash
docker run --rm -it -v "$PWD:/app" -w /app -u "$(id -u):$(id -g)" composer:2 create-project --no-install quire/skeleton mysite
cd mysite
docker compose build --pull
docker compose up --wait
```

> The packages aren't on Packagist yet. Until they are, run Quire from this repository instead:
>
> ```bash
> git clone https://github.com/quirecms/framework.git quire
> cd quire
> docker compose build --pull --no-cache
> docker compose up --wait
> ```

Then set up the site and its first admin; you're asked for the site's title, its language, and your email and password:

```bash
docker compose exec php bin/console quire:install
```

Open <https://quire.localhost> and accept the locally generated TLS certificate. You should see your new site in the default theme; it lists your posts once you [write some](#content). The admin interface is at <https://quire.localhost/admin> (see [docs/admin.md](docs/admin.md)).

Names ending in `.localhost` always point to your own machine. Browsers and curl resolve them without any `/etc/hosts` entry. To use a different name, set `SERVER_NAME`, e.g. `SERVER_NAME=mysite.localhost docker compose up --wait`.

The health report is at <https://quire.localhost/_quire/health>.

Stop the stack with `docker compose down --remove-orphans`.

### Using other ports

If ports 80 or 443 are already in use on your machine, choose different ones:

```bash
HTTP_PORT=8080 HTTPS_PORT=8443 HTTP3_PORT=8443 docker compose up --wait
```

Then open <https://quire.localhost:8443>.

## Development commands

Run these inside the PHP container, e.g. `docker compose exec php composer test`.

| Command | What it does |
|---------|--------------|
| `composer test` | Run the test suites |
| `composer phpstan` | Static analysis at PHPStan's strictest level |
| `composer cs` | Check coding standards |
| `composer rector` | Show automated refactorings without applying them |
| `composer qa` | Run all of the checks above, the same as CI |
| `composer fix` | Apply coding-standard fixes and refactorings |

The tests create and migrate their own test database before they run.

## Extensions

Install extensions with Composer, then enable them:

```bash
docker compose exec php composer require acme/reviews
docker compose exec php bin/console quire:extension:enable acme-reviews
```

To start your own, generate a working extension (page, setting, migration and tests) and enable it in one step:

```bash
docker compose exec php bin/console quire:make:extension acme/reviews
```

See [docs/extensions.md](docs/extensions.md) for managing extensions and for writing your own.

## Themes

Themes are Composer packages of templates and assets. New sites use `quire/theme-default` (accessible, light and dark, no JavaScript); a theme can inherit from another and change only what it needs:

```bash
docker compose exec php bin/console quire:theme:list
docker compose exec php bin/console quire:theme:use acme-dark
docker compose exec php bin/console quire:make:theme acme/dark    # a new theme, based on the default one
docker compose exec php bin/console quire:theme:lint
```

See [docs/themes.md](docs/themes.md).

## Users

```bash
docker compose exec php bin/console quire:user:create grace@example.com --role=editor
docker compose exec php bin/console quire:user:list
```

Users sign in at `/login`. Registration and password reset by email are off until you turn them on. See [docs/users.md](docs/users.md). What each role may do is set by abilities, such as `content.post.publish`:

```bash
docker compose exec php bin/console quire:permissions:list
docker compose exec php bin/console quire:permissions:grant member content.post.view_drafts
```

See [docs/permissions.md](docs/permissions.md).

## Settings

Change site settings while the site runs:

```bash
docker compose exec php bin/console quire:settings:list
docker compose exec php bin/console quire:settings:set site.title "My site"
```

See [docs/settings.md](docs/settings.md).

## Content

Posts, pages and the content types that extensions add are stored in PostgreSQL:

```bash
docker compose exec php bin/console quire:content:create post "Hello world" --body "My *first* post." --publish
docker compose exec php bin/console quire:content:list
docker compose exec php bin/console quire:content-type:list
docker compose exec php bin/console quire:permalink:list
```

See [docs/content.md](docs/content.md).

## Database

Quire keeps all its tables, and those of its extensions, in a PostgreSQL schema named `quire`. It can share a database with another application, and its migrations run separately from the application's:

```bash
docker compose exec php bin/console quire:migrations:status
```

See [docs/database.md](docs/database.md).

## Storage

Uploaded files are kept on storage disks: a volume by default, or any S3-compatible service, with everything about them in PostgreSQL. Extensions declare the disks they need, and the site decides where each one is:

```bash
docker compose exec php bin/console quire:storage:list
docker compose exec php bin/console quire:storage:check
```

See [docs/storage.md](docs/storage.md).

## Backups

One file holds the site: its database and its uploaded files. Restoring checks first that the code is new enough for the backup:

```bash
docker compose exec -T php bin/console quire:backup - > site.tar
docker compose exec -T php bin/console quire:restore - < site.tar
```

See [docs/backups.md](docs/backups.md).

## Background jobs

Emails, publishing scheduled content and the work of extensions run in the background, in a worker (the `worker` service). Jobs wait in PostgreSQL, saved in the same transaction as the change that caused them, and failed ones are retried:

```bash
docker compose exec php bin/console quire:jobs:status
```

See [docs/jobs.md](docs/jobs.md).

## Audit log

Who did what, in the admin, on the command line and in jobs, is in the audit log, with the console command that does the same:

```bash
docker compose exec php bin/console quire:audit:list --since "-1 day"
```

See [docs/audit.md](docs/audit.md).

## Repository layout

| Path | Contents |
|------|----------|
| `packages/core/` | `quire/core`, the engine |
| `packages/theme-default/` | `quire/theme-default`, the default theme |
| `packages/admin/` | `quire/admin`, the admin interface |
| `packages/testing/` | `quire/testing`, for testing extensions and themes |
| `skeleton/` | What only `quire/skeleton`, the starter project of new sites, has (it's built from the application) |
| `config/`, `src/`, `templates/`, `public/` | The application that develops and tests Quire, and that new sites start from |
| `frankenphp/`, `Dockerfile`, `compose*.yaml` | The Docker setup. See [docs/docker.md](docs/docker.md). |

## Contributing

Bug reports, ideas and pull requests are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md), and our [code of conduct](https://github.com/quirecms/.github/blob/main/CODE_OF_CONDUCT.md). Report security issues privately, as described in [SECURITY.md](SECURITY.md).

## License

Quire is open-source software licensed under the [MIT license](LICENSE). The Quire name and logos are trademarks of YorvoLabs and aren't covered by the license; see [how to use them](https://github.com/quirecms/.github/blob/main/brand/README.md).
