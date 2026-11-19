# syntax=docker/dockerfile:1

# Container image of this PrivateBin 2.1.1 fork.
#
# The application code is taken from this repository. The production
# dependencies in /srv/vendor are the ones already shipped in the base image:
# composer.json and composer.lock are unchanged from upstream 2.1.1, so a
# composer run is not needed and the build works on any machine with a plain
# checkout. After changing the dependencies, run `composer install --no-dev`
# and add a step that copies the vendor directory into the image.
FROM privatebin/nginx-fpm-alpine:2.1.1

USER root

# Admin sessions and multibyte string support are not part of the minimal
# upstream image.
RUN apk add --no-cache php85-session php85-mbstring

# Replace the upstream application code with the version of this repository.
RUN rm -rf /var/www/css /var/www/js /var/www/img /var/www/i18n \
    /srv/lib /srv/tpl /srv/bin

COPY --chown=65534:82 css /var/www/css
COPY --chown=65534:82 js /var/www/js
COPY --chown=65534:82 img /var/www/img
COPY --chown=65534:82 i18n /var/www/i18n
COPY --chown=65534:82 robots.txt manifest.json browserconfig.xml /var/www/
COPY --chown=65534:82 docker/index.php docker/admin.php /var/www/

COPY --chown=65534:82 lib /srv/lib
COPY --chown=65534:82 tpl /srv/tpl
COPY --chown=65534:82 bin /srv/bin

# Hourly maintenance (purge expired documents, remove empty directories),
# supervised by s6 next to nginx and php-fpm.
COPY --chown=65534:82 docker/maintenance/run /etc/s6/services/purge/run
RUN chmod 0755 /etc/s6/services/purge/run

# Configuration and data are not part of the image: mount them at /srv/cfg
# (read only) and /srv/data. The base image already sets CONFIG_PATH=/srv/cfg.
VOLUME ["/srv/data", "/srv/cfg"]

USER 65534:82
