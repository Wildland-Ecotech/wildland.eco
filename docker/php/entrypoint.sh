#!/bin/sh
#
# Starts the app, queue, and scheduler containers on staging and production.
# Caches Laravel's configuration, routes, events, and views before running
# the container's command. The configuration is cached here rather than when
# the image is built, because it includes the settings from laravel/.env,
# which never goes into an image. Each container keeps its own caches.

set -e

php artisan optimize

# The PHP image's own entrypoint, which runs the command
exec docker-php-entrypoint "$@"
