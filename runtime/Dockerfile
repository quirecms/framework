#syntax=docker/dockerfile:1

# Quire's runtime images (ghcr.io/quirecms/runtime), which sites build FROM (their Dockerfile):
#
# - builder (runtime:8.5-builder): FrankenPHP's PHP with Quire's extensions, Composer, the PostgreSQL
#   client tools, PHP's production settings, and the shared libraries the slim image needs. Sites
#   install their packages and build their assets on it; development builds on it too.
# - runtime (runtime:8.5): Debian slim with FrankenPHP, PHP, the extensions and pg_dump/pg_restore,
#   nothing else; sites run on it, as www-data.
#
# Nothing of a site is in them: its Caddyfile, PHP settings and entry point come with its own files.
# They're rebuilt every week and whenever this folder changes (.github/workflows/runtime.yaml), from
# the upstream images' current tags on purpose: PHP's, FrankenPHP's and Debian's security fixes reach
# sites at their next build. Each build is signed (Sigstore), and Quire Server checks the signature.

ARG PHP_VERSION=8.5
# PostgreSQL client tools: at least the server's version (pg_dump refuses newer servers)
ARG POSTGRES_CLIENT_VERSION=18

FROM dunglas/frankenphp:1-php${PHP_VERSION} AS builder

ARG POSTGRES_CLIENT_VERSION

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends ca-certificates curl file git libtree
	install-php-extensions \
		@composer \
		apcu \
		intl \
		opcache \
		pdo_pgsql \
		zip
	# From the PostgreSQL project's repository, not Debian's: pg_dump must be at least the server's version.
	curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/keyrings/postgresql.asc
	. /etc/os-release
	echo "deb [signed-by=/usr/share/keyrings/postgresql.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list
	apt-get update
	apt-get install -y --no-install-recommends "postgresql-client-${POSTGRES_CLIENT_VERSION}"
	rm -rf /var/lib/apt/lists/*
EOF

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# The shared libraries FrankenPHP, PHP, its extensions, file and the PostgreSQL tools need, for the slim image
# hadolint ignore=SC3054
RUN <<-'EOF'
	mkdir -p /opt/quire-runtime/lib
	BINARIES=(frankenphp php file)
	for target in $(printf '%s\n' "${BINARIES[@]}" | xargs -I{} which {}) \
		/usr/lib/postgresql/*/bin/pg_dump /usr/lib/postgresql/*/bin/pg_restore \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			if [ -f "$lib" ]; then cp -n "$lib" /opt/quire-runtime/lib/; fi
		done
	done
EOF

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

WORKDIR /app

FROM debian:13-slim AS runtime

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/php /usr/local/bin/docker-php-entrypoint /usr/local/bin/
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /opt/quire-runtime/lib /usr/lib
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=builder /usr/local/etc/php/php.ini /usr/local/etc/php/php.ini

# CA certificates for TLS, file/libmagic for Symfony's MIME type detection, the PostgreSQL tools
COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=builder /etc/ssl/openssl.cnf /etc/ssl/openssl.cnf
COPY --from=builder /usr/bin/file /usr/bin/file
COPY --from=builder /usr/lib/file/magic.mgc /usr/lib/file/magic.mgc
COPY --from=builder /usr/lib/postgresql/*/bin/pg_dump /usr/lib/postgresql/*/bin/pg_restore /usr/local/bin/

ENV APP_ENV=prod \
    PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d" \
    OPENSSL_CONF=/etc/ssl/openssl.cnf \
    SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

RUN <<-EOF
	mkdir -p /data/caddy /config/caddy /usr/local/etc/php/app.conf.d /app
	chown -R www-data:www-data /data /config
	# No setuid or setgid programs
	find / -xdev -perm /6000 -type f -exec chmod a-s {} + 2>/dev/null || true
	# It runs: PHP, FrankenPHP and the PostgreSQL tools
	php -v
	frankenphp version
	pg_dump --version
	php -m | grep -qx pdo_pgsql
EOF

WORKDIR /app
USER www-data
