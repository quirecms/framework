# Quire

A text-first CMS for PHP, by [YorvoLabs](https://quirecms.com).

Quire has a small core, and everything else is an extension: the admin panel, single sign-on, comments, reviews. You write content in Markdown, and PostgreSQL is the single source of truth, so one database backup holds your whole site.

> **Status:** early development. The engine runs, but content, extensions and theming aren't available yet.

## Requirements

- Docker with Docker Compose v2.10 or later

To run without Docker you need PHP 8.5 (with the `intl`, `opcache`, `apcu`, `zip` and `pdo_pgsql` extensions), Composer, and PostgreSQL 18.

## Quick start

```bash
git clone https://github.com/quirecms/framework.git quire
cd quire
docker compose build --pull --no-cache
docker compose up --wait
```

Open <https://quire.localhost> and accept the locally generated TLS certificate. You should see the Quire welcome page.

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

Before running the tests for the first time, create the test database:

```bash
docker compose exec php bin/console -e test doctrine:database:create --if-not-exists
```

## Repository layout

| Path | Contents |
|------|----------|
| `packages/core/` | `quire/core`, the engine |
| `config/`, `src/`, `templates/`, `public/` | The application that runs Quire. Site-specific overrides go here. |
| `frankenphp/`, `Dockerfile`, `compose*.yaml` | The Docker setup. See [docs/docker.md](docs/docker.md). |

## License

Quire is open-source software licensed under the [MIT license](LICENSE).
