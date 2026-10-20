#!/usr/bin/env bash
#
# Deploys the latest commit of this server's branch.
#
# Runs on staging and production servers from the repository root, which
# must be a clone used only for deploys: local changes to tracked files are
# discarded. Untracked files such as .env and laravel/.env are kept.
#
# The branch comes from DEPLOY_BRANCH in the root .env (main for staging,
# release for production). See docs/07-deployment.md.
#
# Usage: deploy/deploy.sh

set -euo pipefail

# All work happens in functions called from the last line. Bash reads a
# script as it runs, and `git reset` below can replace this file mid-run;
# defining everything up front means the new version is never half-read.

env_value() {
    # Reads KEY=value from the root .env without executing the file;
    # prints nothing if the key is missing
    { grep -E "^$1=" .env || true; } | tail -n 1 | cut -d '=' -f 2-
}

log() {
    echo "[$(date -u '+%Y-%m-%d %H:%M:%S UTC')] $*"
}

deploy() {
    local branch
    branch="$(env_value DEPLOY_BRANCH)"

    if [[ -z "$branch" ]]; then
        log "DEPLOY_BRANCH is not set in .env; refusing to deploy"
        exit 1
    fi

    log "Updating to the latest commit on $branch"
    git fetch --quiet origin "$branch"
    git reset --quiet --hard "origin/$branch"
    log "Deploying $(git log -1 --format='%h %s')"

    log "Building and starting containers"
    docker compose build --quiet
    docker compose up --detach --remove-orphans

    log "Installing PHP dependencies"
    docker compose exec -T app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

    log "Building frontend assets"
    docker compose run --rm node npm ci
    docker compose run --rm node npm run build

    log "Running migrations"
    docker compose exec -T app php artisan migrate --force

    log "Caching configuration, routes, and views"
    docker compose exec -T app php artisan optimize

    log "Deploy finished"
}

main() {
    cd "$(dirname "$0")/.."

    # Wait for any deploy already running in this environment, so a push that
    # arrives mid-deploy is still deployed afterwards
    local lock
    lock="/tmp/$(env_value APP_NAME)-deploy.lock"
    exec 9>"$lock"
    flock 9

    deploy
}

main "$@"
exit
