# Nextcloud image with the FaceRecognition app and all its requirements
# (pdlib, bz2, and enough memory) already installed.
#
# Build it from the root of the repository:
#     docker build -f docker/Dockerfile -t facerecognition-nextcloud .
# or just use the compose file:
#     docker compose -f docker/compose.yaml up -d --build

ARG NEXTCLOUD_VERSION=34
ARG NODE_VERSION=20


##
## Assets: same steps than the `make build` rule of the Makefile.
##
FROM node:${NODE_VERSION}-bookworm AS assets

WORKDIR /build

RUN apt-get update && \
    apt-get install -y --no-install-recommends curl && \
    rm -rf /var/lib/apt/lists/*

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# npm run build (webpack) + the javascript-deps and js-templates rules.
RUN npm run build && \
    mkdir -p js/vendor && \
    cp node_modules/handlebars/dist/handlebars.js js/vendor/handlebars.js && \
    cp node_modules/lozad/dist/lozad.js js/vendor/lozad.js && \
    cp src/dialogs.js js/facerecognition-dialogs.js && \
    node_modules/handlebars/bin/handlebars src/templates -f js/facerecognition-templates.js && \
    curl -fsSL https://raw.githubusercontent.com/realsuayip/autocomplete/master/dist/autocomplete.js \
         -o js/vendor/autocomplete.js && \
    curl -fsSL https://raw.githubusercontent.com/mikeflynn/egg.js/master/egg.js \
         -o js/vendor/egg.js

# Leave in /app only what the appstore rule of the Makefile would ship.
RUN mkdir -p /app && \
    cp -a appinfo css img js l10n lib templates CHANGELOG.md LICENSE README.md /app/


##
## Nextcloud with the requirements of the app, and the app itself.
##
FROM nextcloud:${NEXTCLOUD_VERSION}-apache

# Same pdlib package used by the CI of the project. It bundles dlib, so
# compiling it takes a while (and quite a lot of RAM).
ARG PDLIB_URL=https://services.delellis.com.ar/data/facerecognition/pdlib-1.1.0.tgz

ARG PDLIB_BUILD_DEPS="build-essential libbz2-dev liblapack-dev libopenblas-dev libpng-dev"

RUN set -eux; \
    apt-get update; \
    # Runtime libraries of pdlib, installed as manual so that they survive the
    # cleanup of the build dependencies below.
    apt-get install -y --no-install-recommends \
        libopenblas0 \
        liblapack3; \
    apt-get install -y --no-install-recommends $PDLIB_BUILD_DEPS; \
    docker-php-ext-install -j"$(nproc)" bz2; \
    \
    # Note that pdlib is built in tree, and not with `pecl install`: the out of
    # tree build of pecl fails, as libtool can not create the objects of the
    # bundled dlib (dlib/dlib/all/source.loT: No such file or directory).
    mkdir -p /usr/src/pdlib; \
    curl -fsSL "$PDLIB_URL" -o /tmp/pdlib.tgz; \
    tar -xzf /tmp/pdlib.tgz -C /usr/src/pdlib --strip-components=1 --exclude=package.xml; \
    cd /usr/src/pdlib; \
    phpize; \
    ./configure; \
    make -j"$(nproc)"; \
    make install; \
    cd /; \
    rm -rf /usr/src/pdlib /tmp/pdlib.tgz; \
    docker-php-ext-enable pdlib; \
    \
    apt-get purge -y --auto-remove $PDLIB_BUILD_DEPS; \
    rm -rf /var/lib/apt/lists/*; \
    \
    php -r 'exit(extension_loaded("pdlib") && extension_loaded("bz2") ? 0 : 1);'; \
    php --ri pdlib

# The app is picked up from here by the entrypoint of the image, the first
# time that /var/www/html/custom_apps is populated.
COPY --from=assets /app /usr/src/nextcloud/custom_apps/facerecognition

# occ app:enable + occ face:setup, right after the installation of Nextcloud.
COPY docker/hooks/ /docker-entrypoint-hooks.d/
RUN chmod -R +x /docker-entrypoint-hooks.d/

# The app needs 1GB of memory just for the analysis of a single image.
ENV PHP_MEMORY_LIMIT=2048M
ENV PHP_UPLOAD_LIMIT=1024M
