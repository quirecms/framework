#syntax=docker/dockerfile:1

# The site's images, built on Quire's runtime images (ghcr.io/quirecms/runtime: PHP with Quire's
# extensions, FrankenPHP, the PostgreSQL tools), which are rebuilt every week with PHP's and Debian's
# security fixes and signed: a site gets them at its next build. Quire Server checks their signature
# and passes the digests it checked (RUNTIME_BUILDER, RUNTIME).
#
# The different stages are meant to be built into separate images
# https://docs.docker.com/build/building/multi-stage/#stop-at-a-specific-build-stage
# https://docs.docker.com/reference/compose-file/build/#target
ARG RUNTIME_BUILDER=ghcr.io/quirecms/runtime:8.5-builder
ARG RUNTIME=ghcr.io/quirecms/runtime:8.5

# Base FrankenPHP image: the runtime's builder with the site's PHP settings, Caddyfile and entry point
FROM ${RUNTIME_BUILDER} AS frankenphp_base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

###> recipes ###
###< recipes ###

COPY --link frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY --link frankenphp/Caddyfile /etc/frankenphp/Caddyfile

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# Dev FrankenPHP image
FROM frankenphp_base AS frankenphp_dev

ENV APP_ENV=dev
ENV XDEBUG_MODE=off
ENV FRANKENPHP_WORKER_CONFIG=watch

# dev dependencies
RUN <<-EOF
	cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	install-php-extensions xdebug
	useradd -m -s /bin/bash nonroot
	git config --system --add safe.directory /app
EOF

COPY --link frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--watch" ]

# Builder for the prod FrankenPHP image
FROM frankenphp_base AS frankenphp_prod_builder

ENV APP_ENV=prod

COPY --link frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# prevent the reinstallation of vendors at every changes in the source code
COPY --link composer.* symfony.* ./
# >>> development only
# Monorepo packages are installed from Composer path repositories, so they must exist before `composer install`
COPY --link packages/ packages/
# <<< development only
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# copy sources
COPY --link --exclude=frankenphp/ . ./

RUN <<-EOF
	mkdir -p var/cache var/log var/share public/assets
	composer dump-autoload --classmap-authoritative --no-dev
	composer dump-env prod
	composer run-script --no-dev post-install-cmd
	if [ -f importmap.php ]; then
		php bin/console asset-map:compile
	fi
	chmod +x bin/console
	chmod -R g=u var
	sync
EOF

# The shared libraries of extensions the site added (recipes above): the runtime has its own already.
# hadolint ignore=SC3054
RUN <<-'EOF'
	mkdir -p /tmp/libs
	for target in $(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			if [ -f "$lib" ] && [ ! -f "/opt/quire-runtime/lib/$(basename "$lib")" ]; then cp -n "$lib" /tmp/libs/; fi
		done
	done
EOF

# Prod FrankenPHP image: the slim runtime, with the site
FROM ${RUNTIME} AS frankenphp_prod

USER root

COPY --from=frankenphp_prod_builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=frankenphp_prod_builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=frankenphp_prod_builder /usr/local/etc/php/app.conf.d /usr/local/etc/php/app.conf.d
COPY --from=frankenphp_prod_builder /tmp/libs /usr/lib
COPY --from=frankenphp_prod_builder /etc/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

COPY --link --exclude=var --exclude=public/assets --from=frankenphp_prod_builder /app /app
# Writable at runtime: var/, and the compiled assets, which are rebuilt when extensions or the theme
# change. Group 0 + g=u for arbitrary-UID runtimes (e.g. OpenShift).
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/var /app/var
COPY --chown=www-data:0 --from=frankenphp_prod_builder /app/public/assets /app/public/assets
# storage/ holds the local storage disks; mount a volume there (https://github.com/quirecms/framework/blob/main/docs/storage.md).
RUN <<-EOF
	mkdir -p /app/storage/public /app/storage/private
	chown -R www-data:0 /app/storage
	chmod -R g=u /app/var /app/public/assets /app/storage
EOF

COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

USER www-data

WORKDIR /app

ENTRYPOINT ["docker-entrypoint"]

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
