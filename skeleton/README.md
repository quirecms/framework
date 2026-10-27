# Quire site

A new site built with [Quire](https://quirecms.com), the extensible CMS for PHP on Symfony. It comes with Quire's core, its default theme and the admin interface. Everything else, such as single sign-on or comments, is an extension you add with Composer.

## Create a site

With only Docker on your machine (Docker Compose v2.10 or later):

```bash
docker run --rm -it -v "$PWD:/app" -w /app -u "$(id -u):$(id -g)" composer:2 create-project --no-install quire/skeleton mysite
cd mysite
docker compose build --pull
docker compose up --wait
docker compose exec php bin/console quire:install
```

The first start installs the dependencies, so it takes a minute. `quire:install` asks for the site's title, its language, and your email and password; you're the site's first admin.

If you have PHP 8.5 and Composer on your machine, `composer create-project quire/skeleton mysite` does the first step instead.

Open <https://quire.localhost> and accept the locally generated TLS certificate. The admin interface is at <https://quire.localhost/admin>. To use another name, set `SERVER_NAME`, e.g. `SERVER_NAME=mysite.localhost docker compose up --wait`. If ports 80 or 443 are taken, choose others: `HTTP_PORT=8080 HTTPS_PORT=8443 HTTP3_PORT=8443 docker compose up --wait`.

## Run it in production

On a server with Docker, whose domain's DNS points at it:

```bash
cp prod.env.dist prod.env && chmod 600 prod.env
sed -i "s/^APP_SECRET=.*/APP_SECRET=$(openssl rand -hex 32)/; s/^POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=$(openssl rand -hex 24)/" prod.env
# set SERVER_NAME (the domain) and MAILER_DSN in prod.env, then:
docker compose --env-file prod.env build --pull
docker compose --env-file prod.env up --wait
docker compose --env-file prod.env exec php bin/console quire:install
```

`prod.env` holds the site's secrets: Git and the Docker image never include it, so keep a copy somewhere safe. Caddy obtains the TLS certificate and adds security headers. See [Docker in production](https://github.com/quirecms/framework/blob/main/docs/docker.md#production).

## What's here

| Path | Contents |
|------|----------|
| `extend.php` | Small changes to the site with the same extenders that extensions use |
| `templates/` | Your versions of the theme's templates, e.g. `templates/content/single.html.twig` |
| `assets/` | The site's own styles and JavaScript, loaded after the theme's |
| `config/` | Symfony and Quire configuration; `config/packages/quire.yaml` for Quire's |
| `src/` | Your own PHP code, in the namespace `App\` |
| `Dockerfile`, `compose*.yaml`, `frankenphp/` | The Docker setup, for development and production |
| `prod.env.dist` | The template of `prod.env`, the production settings and secrets |

Content, users and settings are kept in PostgreSQL, and uploaded files on the `storage_data` volume, not in this directory: keep it in Git, and back up the site with `bin/console quire:backup`.

## Documentation

- [Extensions](https://github.com/quirecms/framework/blob/main/docs/extensions.md): install, enable, and write your own
- [Themes](https://github.com/quirecms/framework/blob/main/docs/themes.md) and [the admin](https://github.com/quirecms/framework/blob/main/docs/admin.md)
- [Content](https://github.com/quirecms/framework/blob/main/docs/content.md), [users](https://github.com/quirecms/framework/blob/main/docs/users.md), [permissions](https://github.com/quirecms/framework/blob/main/docs/permissions.md) and [settings](https://github.com/quirecms/framework/blob/main/docs/settings.md)
- [Docker, in development and production](https://github.com/quirecms/framework/blob/main/docs/docker.md), [storage](https://github.com/quirecms/framework/blob/main/docs/storage.md), [backups](https://github.com/quirecms/framework/blob/main/docs/backups.md) and [background jobs](https://github.com/quirecms/framework/blob/main/docs/jobs.md)

Quire is developed at [quirecms/framework](https://github.com/quirecms/framework); report issues there. This repository is built from the application there, and is read-only.
