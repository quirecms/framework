# Running Quire with Docker

Quire runs on [FrankenPHP](https://frankenphp.dev) (a PHP application server built on Caddy) in worker mode, with PostgreSQL 18. The setup is based on [dunglas/symfony-docker](https://github.com/dunglas/symfony-docker).

## Services

| Service | Image | Purpose |
|---------|-------|---------|
| `php` | Built from `Dockerfile` | FrankenPHP + PHP 8.5. Serves HTTP, HTTPS and HTTP/3. |
| `worker` | The `php` service's image | Runs [background jobs](jobs.md) and scheduled tasks, e.g. sending emails and publishing scheduled content. It starts once `php` is healthy (the migrations ran), restarts by itself after extensions change, and in development every 5 minutes, to run code you changed. Its health check reads its heartbeat. |
| `database` | `postgres:18-alpine` | Stores all of Quire's data |
| `mailer` | `axllent/mailpit` | Development only: catches the emails Quire sends, e.g. to reset passwords, and shows them in a web inbox |
| `s3` | `chrislusf/seaweedfs` | Development only: an S3-compatible service, to try [storage disks](storage.md) on S3 and to test them. Its S3 API is at the address `docker compose port s3 8333` shows; it accepts any credentials. |

## Files

| File | Used for |
|------|----------|
| `compose.yaml` | Base configuration shared by every environment |
| `compose.override.yaml` | Development. Docker Compose loads it automatically. |
| `compose.prod.yaml` | Production, with `prod.env` (see [production](#production)) |
| `compose.proxy.yaml` | Production behind a reverse proxy (see [below](#behind-a-reverse-proxy)) |
| `prod.env.dist` | The template of `prod.env`, a site's production settings and secrets |
| `Dockerfile` | One file with three targets: `frankenphp_dev`, `frankenphp_prod_builder` and `frankenphp_prod`, built on [Quire's runtime images](#the-runtime-images) |
| `frankenphp/Caddyfile` | Web server configuration |
| `frankenphp/conf.d/` | PHP settings: shared, dev-only and prod-only |
| `frankenphp/docker-entrypoint.sh` | Installs dependencies if needed, waits for the database, runs migrations (unless `QUIRE_SKIP_MIGRATIONS=1`) |

A site's containers, volumes and images are named after its directory, e.g. `mysite-php-1` and `mysite_storage_data`, so several sites can run on one machine. Those of this repository are always named `quire`.

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

The site's production settings and secrets live in one file, `prod.env`, next to `compose.yaml`. Create it once from the template, with new secrets:

```bash
cp prod.env.dist prod.env
chmod 600 prod.env
sed -i "s/^APP_SECRET=.*/APP_SECRET=$(openssl rand -hex 32)/; s/^POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=$(openssl rand -hex 24)/" prod.env
```

Then set the site's domain (`SERVER_NAME`) and, to send emails, `MAILER_DSN` in it, and start the site:

```bash
docker compose --env-file prod.env build --pull
docker compose --env-file prod.env up --wait
```

Every later command takes the same `--env-file prod.env`, e.g. `docker compose --env-file prod.env exec php bin/console quire:backup - > site.tar`. The file also tells Compose to use `compose.prod.yaml` (`COMPOSE_FILE`), so there's no `-f` to repeat.

- **Kept out of Git and the image.** `prod.env` is in `.gitignore` and `.dockerignore`. Keep a copy somewhere safe, e.g. a password manager: the site's backups don't contain it.
- **Nothing missing goes unnoticed.** Without `SERVER_NAME`, `APP_SECRET` or `POSTGRES_PASSWORD`, Compose stops with a message saying which one to set, instead of starting the site with a development default. (Exported shell variables still work, and win over the file, but they're gone after you sign out, which is why the file is the way to go.)
- **Keep the secrets.** Changing `APP_SECRET` signs everyone out. `POSTGRES_PASSWORD` is only read when the database is created: to change it later, change it in PostgreSQL too (`ALTER USER app PASSWORD '…'`).

When `SERVER_NAME` is a public domain, Caddy obtains a TLS certificate automatically. The domain's DNS must point at the server, and ports 80 and 443 must be reachable.

The production image:

- runs as the unprivileged `www-data` user;
- has OPcache preloading enabled and doesn't re-check PHP files for changes;
- runs pending database migrations when it starts;
- compiles the assets (CSS, JavaScript, images) of the site, its extensions and its theme into `public/assets/` when it's built, and again when it starts and whenever extensions or the theme change. Caddy serves them with a one-year `immutable` cache header, since every file has its version in its name.

### Security headers

Caddy adds these headers to every response, unless Quire set its own (for example, private pages can't be shown in a frame at all):

| Header | Value | What it does |
|--------|-------|--------------|
| `Strict-Transport-Security` | `max-age=31536000` | Browsers use only HTTPS for the site for a year (`QUIRE_HSTS_MAX_AGE`; `0` in development) |
| `X-Content-Type-Options` | `nosniff` | Browsers don't guess a file's type, e.g. run an uploaded text file as a script |
| `X-Frame-Options` | `SAMEORIGIN` | Other sites can't show the site in a frame (against clickjacking) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Other sites see only the site's address, not the page a visitor came from |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` | Pages can't ask for the camera, microphone, location, payments or USB |
| `Cross-Origin-Opener-Policy` | `same-origin` | Windows the site opens on other sites can't reach back into it |

The `Server` header is removed. To change one, set it in your own Caddy directives (`CADDY_SERVER_EXTRA_DIRECTIVES`), or in a response, for a page of your own. HSTS covers only the site's own domain, not its subdomains. To go further (`includeSubDomains`, `preload`), make sure every subdomain has HTTPS first, since browsers remember it.

### Behind a reverse proxy

When another web server or a load balancer holds the site's domain and its certificate, for example nginx or Apache already serving other sites on the machine, the site runs behind it: plain HTTP inside, on a port only the proxy reaches. Add `compose.proxy.yaml` in `prod.env`:

```bash
COMPOSE_FILE=compose.yaml:compose.prod.yaml:compose.proxy.yaml
SERVER_NAME=example.com
```

The site then:

- **Listens on `127.0.0.1:8080`** of the machine, over HTTP only: no certificate of its own, and ports 80 and 443 stay the proxy's. `HTTP_PORT` changes the port; `HTTP_HOST_IP` the address, for a proxy on another machine (then let only the proxy through the firewall).
- **Believes the proxy about the visitor:** their address and that they came over HTTPS (`X-Forwarded-For`, `-Proto` and `-Port`), from proxies on private networks: this machine, Docker's networks. So links, redirects and cookies use HTTPS, and sign-in cookies are `secure`. When `HTTP_HOST_IP` lets other machines in, set `SYMFONY_TRUSTED_PROXIES` to the proxy's own address instead, or anyone who can reach the port could claim to be the proxy.
- **Uses `https://` + `SERVER_NAME` for links in emails** and from the command line; set `DEFAULT_URI` when visitors use another address, e.g. one with a port.

The proxy passes the requests on with their `Host` header and the forwarded headers. For example:

```nginx
# nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Port $server_port;
}
```

```apache
# Apache (mod_proxy, mod_headers), in the site's <VirtualHost *:443>
ProxyPreserveHost On
ProxyPass / http://127.0.0.1:8080/
ProxyPassReverse / http://127.0.0.1:8080/
RequestHeader set X-Forwarded-Proto "https"
RequestHeader set X-Forwarded-Port "443"
```

```caddyfile
# Caddy: sets the forwarded headers itself
example.com {
    reverse_proxy 127.0.0.1:8080
}
```

The [security headers](#security-headers) still come from the site, through the proxy. HTTP/3 is the proxy's to offer.

### Persistent data

Keep these volumes:

| Volume | Contents |
|--------|----------|
| `database_data` | PostgreSQL data: everything but the bytes of uploaded files. Back it up, and the files, with `quire:backup` (see [backups](backups.md)). |
| `storage_data` | Uploaded files on local [storage disks](storage.md). `quire:backup` includes them. |
| `caddy_data` | TLS certificates and keys |
| `caddy_config` | Caddy's runtime configuration |

### Running several servers

The app containers keep no data of their own (sessions too are in PostgreSQL), so you can run several behind a load balancer, all using the same PostgreSQL database; users stay signed in whichever server answers. Put [storage disks](storage.md) on S3 (or a volume all servers share), since a local disk is only on the server that wrote to it. When an extension is enabled or disabled on one of them, the others notice within a few seconds, rebuild in the background and restart their workers, with no extra setup and no message broker. Run the `worker` service on as many servers as you like: each job and each scheduled task runs once. Tune or disable the check with `quire.extensions.check_interval` (in seconds; default `5`, `0` turns it off).

For the same set of extensions everywhere, set by your deployment instead of at runtime, pin them in `config/packages/quire.yaml` (see [extensions](extensions.md#pinning-extensions-in-production)).

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `SERVER_NAME` | `quire.localhost` | Domain(s) Caddy serves. Required in production (`prod.env`). |
| `HTTP_PORT` / `HTTPS_PORT` / `HTTP3_PORT` | `80` / `443` / `443` | Ports published on the host |
| `APP_SECRET` | none (required in production) | Symfony's application secret |
| `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` | `app` / `app` / `!ChangeMe!` | Database credentials. The password is required in production (`prod.env`). |
| `POSTGRES_VERSION` | `18` | PostgreSQL major version |
| `MAILER_DSN` | `null://null` (Mailpit in development) | Where emails go, e.g. `smtp://user:pass@smtp.example.com:587`. Needed for registration and password reset. |
| `DEFAULT_URI` | `https://$SERVER_NAME:$HTTPS_PORT` | The site's address, used for links in emails and from the command line |
| `XDEBUG_MODE` | `off` | Xdebug mode (development only) |
| `SYMFONY_TRUSTED_PROXIES` | none (`private_ranges` with `compose.proxy.yaml`) | Proxies whose `X-Forwarded-*` headers to believe: addresses, ranges, or `private_ranges` (see [behind a reverse proxy](#behind-a-reverse-proxy)) |
| `HTTP_HOST_IP` | `127.0.0.1` (with `compose.proxy.yaml`) | The machine's address the site listens on behind a proxy |
| `QUIRE_HSTS_MAX_AGE` | `31536000` (`0` in development) | How long browsers keep to HTTPS for the site, in seconds (see [security headers](#security-headers)) |
| `CADDY_SERVER_EXTRA_DIRECTIVES` | none | Caddy directives added to the site's, e.g. headers of your own |
| `QUIRE_JOBS_DSN` | `quire://` (PostgreSQL) | Where [background jobs](jobs.md) wait for workers; `sync://` runs them at once, without a worker |
| `QUIRE_SKIP_MIGRATIONS` | `0` | `1` makes the entrypoint skip migrations, for containers started next to the one that runs them (the `worker` service) |
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

## Tools that manage sites

A server that runs several sites, such as [Quire Server](https://github.com/quirecms/server), finds and manages them through a small, versioned contract (`Quire\Runtime\ServerContract`, currently version 1):

- **Labels** on the `php` container: `com.quirecms.site=true` and `com.quirecms.contract=1`, so a site already running on a machine can be found.
- **`bin/console quire:about --format=json`**: the site's Quire, PHP and Symfony versions, title, language, time zone, address, whether it's installed (has an admin), its extensions and theme, its migrations (run and pending) and its health checks, with the contract's version. Parts that need the database are `null` when it can't be reached. Without `--format`, the same for people.
- **`bin/console quire:install --no-interaction --admin-email=… --invite`**: a new site, its first admin invited by email.
- **`compose.proxy.yaml`**: the site behind the server's own front door ([behind a reverse proxy](#behind-a-reverse-proxy)).

```bash
docker compose --env-file prod.env exec -T php bin/console quire:about --format=json
```

```json
{
    "contract": 1,
    "quire": {"version": "v0.1.0", "reference": "744b987…"},
    "php": "8.5.12",
    "symfony": "8.1.9",
    "environment": "prod",
    "site": {"title": "Tournament News", "locale": "en", "timezone": "Europe/Skopje", "url": "https://example.com", "installed": true},
    "extensions": [{"id": "quire-admin", "package": "quire/admin", "version": "v0.1.0", "type": "extension", "status": "enabled"}],
    "theme": "quire-theme-default",
    "migrations": {"executed": 19, "pending": 0},
    "health": {"status": "ok", "checks": {"cache": {"status": "ok"}, "database": {"status": "ok"}, "extensions": {"status": "ok"}}}
}
```

A change that such tools must know about raises the contract's version; additions they can ignore don't.

### More sites on the same machine

A site set up as above holds ports 80 and 443, so it's the only one on its machine. For more, install [Quire Server](https://github.com/quirecms/server) (early): its front door holds those ports and the sites' certificates, and it adopts this site. Keep the site's folder in `/srv/quire/sites/<name>` (e.g. `/srv/quire/sites/blog`) from the start, and the installer finds it. Adopting it backs it up, keeps its database and data, and changes two lines of its `prod.env` (the file as it was is kept as `prod.env.before-server`).

### The runtime images

The site's `Dockerfile` builds on Quire's runtime images, so a site doesn't compile PHP's extensions itself, and every site on a machine shares the same layers:

- **`ghcr.io/quirecms/runtime:8.5-builder`**: FrankenPHP's PHP 8.5 with Quire's extensions (`apcu`, `intl`, `opcache`, `pdo_pgsql`, `zip`), Composer, the PostgreSQL client tools and PHP's production settings. The site installs its packages and compiles its assets on it; development runs on it, with Xdebug added.
- **`ghcr.io/quirecms/runtime:8.5`**: Debian slim with FrankenPHP, PHP, the same extensions, `pg_dump` and `pg_restore`, and nothing else. The production image is this, with the site.

They're rebuilt every week from PHP's, FrankenPHP's and Debian's latest images, so **rebuilding a site (`build --pull`) brings their security fixes**, without a Quire release. Each build is signed with Sigstore by Quire's workflow (`runtime.yaml` in quirecms/framework), and also tagged with its date (`8.5-20261102`), to build on exactly that one. Check a signature with:

```bash
cosign verify ghcr.io/quirecms/runtime:8.5 \
  --certificate-identity=https://github.com/quirecms/framework/.github/workflows/runtime.yaml@refs/heads/main \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com
```

[Quire Server](https://github.com/quirecms/server) checks it before building a site, and builds on the digest it checked (the `RUNTIME_BUILDER` and `RUNTIME` build arguments name the images). Every night it also builds its sites again on a newer build, when there is one, so its fixes don't wait for the site's next update.

**Another PHP extension** goes in the `###> recipes ###` block of the `Dockerfile`, e.g. `RUN install-php-extensions gmp`: it's built on the builder and copied into the production image with the libraries it needs.

## Differences from dunglas/symfony-docker

- **The images build on Quire's runtime images** ([above](#the-runtime-images)) instead of `dunglas/frankenphp` directly.
- **Mercure and Vulcain are removed** from the Caddy configuration. Quire doesn't use them in core, and leaving them out keeps the default setup smaller.
- **`packages/` is copied before `composer install`** in the production build, because Quire's packages are installed from Composer path repositories.
- **PostgreSQL 18** is the default, and its volume is mounted at `/var/lib/postgresql`, where PostgreSQL 18+ images keep their data.
- **The app waits for a healthy database** before starting (`depends_on` with `condition: service_healthy`).
- **Xdebug is off by default** in development.
