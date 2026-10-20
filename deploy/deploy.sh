#!/usr/bin/env bash
#
# Deploys the latest commit of this server's branch.
#
# Runs on staging and production servers, from the clone set up by
# deploy/setup.sh. The clone must be used only for deploys: local changes to
# tracked files are discarded. Untracked files such as .env and laravel/.env
# are kept.
#
# The branch comes from DEPLOY_BRANCH in the root .env (main for staging,
# release for production). See docs/07-deployment.md.
#
# Usage: deploy/deploy.sh

set -euo pipefail

# All work happens in functions called from the last line. Bash reads a
# script as it runs, and `git checkout` below can replace this file mid-run;
# defining everything up front means the new version is never half-read.

env_value() {
    # Reads KEY=value from the root .env without executing the file;
    # prints nothing if the key is missing
    { grep -E "^$1=" .env || true; } | tail -n 1 | cut -d '=' -f 2-
}

log() {
    echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] $*"
}

deploy_commit() {
    local branch="$1"

    # Check out the branch at its latest commit, discarding any local changes.
    # Changing DEPLOY_BRANCH in .env is enough to switch the server's branch.
    git checkout --quiet --force -B "$branch" "origin/$branch"
    log "Deploying $(git log -1 --format='%h %s')"

    # The first build on a new server takes several minutes; the build output
    # shows its progress here, or in `journalctl -u wildland-deploy` when run
    # by systemd
    log "Building containers"
    docker compose build

    log "Starting containers"
    docker compose up --detach --remove-orphans

    # Apply Caddyfile changes on servers; `up` does not restart a running
    # container whose compose configuration is unchanged
    if docker compose ps --services --status running | grep -qx caddy; then
        log "Reloading Caddy configuration"
        docker compose exec -T caddy caddy reload --config "/etc/caddy/$(env_value CADDY_SITE).Caddyfile" --adapter caddyfile
    fi

    log "Installing PHP dependencies"
    docker compose exec -T app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

    log "Building frontend assets"
    docker compose run --rm node npm ci
    docker compose run --rm node npm run build

    log "Running migrations"
    docker compose exec -T app php artisan migrate --force

    log "Caching configuration, routes, and views"
    docker compose exec -T app php artisan optimize

    log "Deployed $(git log -1 --format='%h')"
}

deploy() {
    local branch
    branch="$(env_value DEPLOY_BRANCH)"

    if [[ -z "$branch" ]]; then
        log "DEPLOY_BRANCH is not set in .env; refusing to deploy"
        exit 1
    fi

    log "Fetching the latest commit on $branch"
    git fetch --quiet origin "$branch"
    deploy_commit "$branch"

    # Deploy again if more commits were pushed while this deploy ran, since
    # a webhook that arrives mid-deploy does not start a second deploy
    while git fetch --quiet origin "$branch" \
        && [[ "$(git rev-parse HEAD)" != "$(git rev-parse "origin/$branch")" ]]; do
        log "New commits arrived during the deploy"
        deploy_commit "$branch"
    done

    log "Deploy finished"
}

main() {
    cd "$(dirname "$0")/.."

    # Wait for any deploy already running in this environment, so a push that
    # arrives mid-deploy is still deployed afterwards. The lock file lives in
    # the clone's gitignored deploy/trigger directory, so it always belongs to
    # the clone's owner.
    mkdir -p deploy/trigger
    exec 9>deploy/trigger/deploy.lock
    flock 9

    deploy
}

main "$@"
exit
