#syntax=docker/dockerfile:1

# Image de production Synthauto Finance : FrankenPHP (Caddy + PHP 8.3).
# Build prod : composer --no-dev, compilation Tailwind + AssetMapper.
# Deploiement et variables d'env : voir docs/PROD_RENDER.md.
FROM dunglas/frankenphp:1-php8.3 AS base

WORKDIR /app

# Dependances systeme. qpdf : remise a plat des PDF que le parseur libre de FPDI
# refuse (RIB de banque chiffres ou a table xref compressee), avant fusion des pieces
# du dossier de remboursement -- sans lui, le directeur recoit une page "piece non
# affichable" a la place du RIB. Voir FusionPieces::reparer().
RUN apt-get update && apt-get install -y --no-install-recommends \
        acl file gettext git qpdf \
    && rm -rf /var/lib/apt/lists/*

# Extensions PHP : pdo_pgsql (Postgres), redis (cache/messenger), intl, opcache, apcu,
# zip, bcmath (calculs monetaires exacts du recouvrement : bccomp/bcadd/...),
# gd (generation du QR virement SEPA du releve, PngWriter d'endroid/qr-code).
RUN set -eux; \
    install-php-extensions \
        @composer \
        apcu \
        bcmath \
        gd \
        intl \
        opcache \
        zip \
        pdo_pgsql \
        redis \
    ;

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV APP_ENV=prod

# Render (et runtimes durcis type Kubernetes) lancent le conteneur avec un
# capability bounding set reduit. Le binaire frankenphp porte
# cap_net_bind_service=ep ; execve echoue alors avec EPERM (status 126).
# On ecoute sur un port haut ($PORT), cette capability est inutile : on la retire.
RUN setcap -r /usr/local/bin/frankenphp

# Config PHP de production + reglages applicatifs (OPcache).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --link frankenphp/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --link frankenphp/Caddyfile /etc/caddy/Caddyfile
COPY --link --chmod=755 frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# Couche de cache des dependances : ne se reconstruit que si composer.* change.
COPY --link composer.json composer.lock ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

# Sources de l'application.
COPY --link . ./
RUN rm -Rf frankenphp/

# Autoload optimise + scripts Symfony (assets:install, importmap:install, cache:clear),
# puis build des CSS Tailwind et compilation des assets versionnes.
RUN set -eux; \
    mkdir -p var/cache var/log; \
    composer dump-autoload --classmap-authoritative --no-dev; \
    composer run-script --no-dev post-install-cmd; \
    php bin/console tailwind:build --minify; \
    php bin/console asset-map:compile; \
    chmod +x bin/console; sync;
