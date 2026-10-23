# Running Quire with Docker

Quire runs on [FrankenPHP](https://frankenphp.dev) (a PHP application server built on Caddy) in worker mode, with PostgreSQL 18. The setup is based on [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker).

## Services

| Service | Image | Purpose |
|---------|-------|---------|
| `php` | Built from `Dockerfile` | FrankenPHP + PHP 8.5. Serves HTTP, HTTPS and HTTP/3. |
| `database` | `postgres:18-alpine` | Stores all of Quire's data |
| `mailer` | `axllent/mailpit` | Development only: catches the emails Quire sends, e.g. to reset passwords, and shows them in a web inbox |
| `s3` | `chrislusf/seaweedfs` | Development only: an S3-compatible service, to try [storage disks](storage.md) on S3 and to test them. Its S3 API is at the address `docker compose port s3 8333` shows; it accepts any credentials. |

## Files

| File | Used for |
|------|----------|
| `compose.yaml` | Base configuration shared by every environment |
| `compose.override.yaml` | Development. Docker Compose loads it automatically. |
| `compose.prod.yaml` | Production. Pass it explicitly with `-f`. |
| `Dockerfile` | One file with three targets: `frankenphp_dev`, `frankenphp_prod_builder` and `frankenphp_prod` |
| `frankenphp/Caddyfile` | Web server configuration |
| `frankenphp/conf.d/` | PHP settings: shared, dev-only and prod-only |
| `frankenphp/docker-entrypoint.sh` | Installs dependencies if needed, waits for the database, runs migrations |

## Development

```bash
docker compose up --wait
```

In development:

- The project directory is mounted into the container, so code changes apply immediately.
- PHP workers restart automatically when PHP files change (FrankenPHP watch mode).
- Xdebug is installed but off. To turn it on, start the stack with `XDEBUG_MODE=debug docker compose up --wait`.
- Emails go to Mailpit, never to real inboxes. Open its inbox at the address `docker compose port mailer 8025` shows, e.g. `http://localhost:55123`.
- Uploaded files are kept on the `storage_data` volume, mounted at `storage/`, so they aren't in your working copy.

### File ownership on Linux

Commands run in the container as root by default, so files they create (for example by `composer require`) end up owned by root on your machine. To avoid that, run them as your own user:

```bash
docker compose exec -u "$(id -u):$(id -g)" -e HOME=/tmp php composer require vendor/package
```

If files already belong to root, give them back to your user:

```bash
docker compose run --rm --no-deps --entrypoint sh php -c "chown -R $(id -u):$(id -g) /app"
```

## Production

The production image contains only the code. All data lives in PostgreSQL, so the app container can be replaced at any time.

```bash
export APP_SECRET="$(openssl rand -hex 16)"
export POSTGRES_PASSWORD="$(openssl rand -hex 16)"
export SERVER_NAME=example.com

docker compose -f compose.yaml -f compose.prod.yaml build --pull
docker compose -f compose.yaml -f compose.prod.yaml up --wait
```

When `SERVER_NAME` is a public domain, Caddy obtains a TLS certificate automatically. The domain's DNS must point at the server, and ports 80 and 443 must be reachable.

The production image:

- runs as the unprivileged `www-data` user;
- has OPcache preloading enabled and doesn't re-check PHP files for changes;
- runs pending database migrations when it starts;
- compiles the assets (CSS, JavaScript, images) of the site, its extensions and its theme into `public/assets/` when it's built, and again when it starts and whenever extensions or the theme change. Caddy serves them with a one-year `immutable` cache header, since every file has its version in its name.

### Persistent data

Keep these volumes:

| Volume | Contents |
|--------|----------|
| `database_data` | PostgreSQL data: everything but the bytes of uploaded files. Back it up, and the files, with `quire:backup` (see [backups](backups.md)). |
| `storage_data` | Uploaded files on local [storage disks](storage.md). `quire:backup` includes them. |
| `caddy_data` | TLS certificates and keys |
| `caddy_config` | Caddy's runtime configuration |

### Running several servers

The app containers keep no data of their own (sessions too are in PostgreSQL), so you can run several behind a load balancer, all using the same PostgreSQL database; users stay signed in whichever server answers. Put [storage disks](storage.md) on S3 (or a volume all servers share), since a local disk is only on the server that wrote to it. When an extension is enabled or disabled on one of them, the others notice within a few seconds, rebuild in the background and restart their workers, with no extra setup and no message broker. Tune or disable the check with `quire.extensions.check_interval` (in seconds; default `5`, `0` turns it off).

For the same set of extensions everywhere, set by your deployment instead of at runtime, pin them in `config/packages/quire.yaml` (see [extensions](extensions.md#pinning-extensions-in-production)).

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SERVER_NAME` | `quire.localhost` | Domain(s) Caddy serves. Always set it in production. |
| `HTTP_PORT` / `HTTPS_PORT` / `HTTP3_PORT` | `80` / `443` / `443` | Ports published on the host |
| `APP_SECRET` | none (required in prod) | Symfony's application secret |
| `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` | `app` / `app` / `!ChangeMe!` | Database credentials. **Always change the password in production.** |
| `POSTGRES_VERSION` | `18` | PostgreSQL major version |
| `MAILER_DSN` | `null://null` (Mailpit in development) | Where emails go, e.g. `smtp://user:pass@smtp.example.com:587`. Needed for registration and password reset. |
| `DEFAULT_URI` | `https://$SERVER_NAME:$HTTPS_PORT` | The site's address, used for links in emails and from the command line |
| `XDEBUG_MODE` | `off` | Xdebug mode (development only) |
| `QUIRE_FRANKENPHP_ADMIN_URL` | `http://localhost:2019` | Where Quire reaches FrankenPHP's admin API to restart PHP workers after extensions change |

## Health checks

Quire exposes two kinds of health check:

| Check | Where | What it tells you |
|-------|-------|-------------------|
| **Liveness** | The image's Docker `HEALTHCHECK` | The server process responds. It doesn't check the database, so a database outage won't trigger a restart loop. |
| **Readiness** | `GET /_quire/health` | Whether Quire can serve requests. Returns `200` with a JSON report, or `503` if a check fails. Point load balancers and uptime monitors here. |

Example response:

```json
{"status":"ok","checks":{"cache":{"status":"ok"},"database":{"status":"ok"}}}
```

## Differences from dunglas/symfony-docker

- **Mercure and Vulcain are removed** from the Caddy configuration. Quire doesn't use them in core, and leaving them out keeps the default setup smaller.
- **`packages/` is copied before `composer install`** in the production build, because Quire's packages are installed from Composer path repositories.
- **PostgreSQL 18** is the default, and its volume is mounted at `/var/lib/postgresql`, where PostgreSQL 18+ images keep their data.
- **The app waits for a healthy database** before starting (`depends_on` with `condition: service_healthy`).
- **Xdebug is off by default** in development.
