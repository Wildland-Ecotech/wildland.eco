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
    # The running containers don't use the clone's files, so this changes
    # nothing on the site until they are replaced below.
    git checkout --quiet --force -B "$branch" "origin/$branch"
    log "Deploying $(git log -1 --format='%h %s')"

    # A commit that changes this script deploys with its own version: start
    # over with the new script, which finds the branch already checked out
    if [[ "$(git hash-object deploy/deploy.sh)" != "$SCRIPT_HASH" ]]; then
        log "The deploy script changed; restarting with the new version"
        exec deploy/deploy.sh
    fi

    # Images are tagged with the commit, so earlier releases' images stay
    # available for a rollback (see docs/07-deployment.md). Exported for the
    # commands below; saved to .env once the site has switched.
    export IMAGE_TAG
    IMAGE_TAG="$(git rev-parse --short=12 HEAD)"

    # The site keeps running the old containers while the new images build.
    # The first build on a new server takes several minutes; the build output
    # shows its progress here, or in `journalctl -u wildland-deploy` when run
    # by systemd.
    log "Building images"
    docker compose build

    copy_host_storage

    # Fails the deploy before the site switches over if the Stripe keys
    # don't match this site's mode: live mode only on https://wildland.eco,
    # test mode everywhere else. Starting the container also caches the
    # configuration, so a broken laravel/.env stops the deploy here too.
    log "Checking the Stripe configuration"
    docker compose run --rm app php artisan stripe:check

    # Runs from the new image just before the switch. The old code runs
    # against the new schema for a few seconds, which is safe for the
    # additive migrations we write.
    log "Running migrations"
    docker compose run --rm app php artisan migrate --force

    # Replaces the app, queue, scheduler, and nginx containers, even when the
    # images are unchanged, so a deploy by hand also applies changes to
    # laravel/.env. Waits until app and nginx pass their health checks.
    log "Switching to the new containers"
    docker compose up --detach --no-deps --force-recreate --wait --wait-timeout 180 \
        app queue scheduler nginx
    save_image_tag "$IMAGE_TAG"

    # Starts or updates the other services where their configuration changed,
    # and removes containers of services no longer in docker-compose.yml, such
    # as a Stripe CLI started from the development compose file
    docker compose up --detach --remove-orphans --wait --wait-timeout 180

    # Apply Caddyfile changes on servers; `up` does not restart a running
    # container whose compose configuration is unchanged
    if docker compose ps --services --status running | grep -qx caddy; then
        log "Reloading Caddy configuration"
        docker compose exec -T caddy caddy reload --config "/etc/caddy/$(env_value CADDY_SITE).Caddyfile" --adapter caddyfile
    fi

    remove_old_images

    log "Deployed $(git log -1 --format='%h')"
}

# Until the switch to images, laravel/storage on the host held the logs,
# sessions, and stored files. Copies them into the storage volume once, on
# the first deploy that uses it; later deploys skip this.
copy_host_storage() {
    docker compose run --rm --no-deps --entrypoint sh \
        --volume "$PWD/laravel/storage:/host-storage:ro" \
        app -c '
            if [ ! -e storage/.copied-from-host ]; then
                echo "Copying laravel/storage from the host into the storage volume"
                cp -R /host-storage/. storage/
                touch storage/.copied-from-host
            fi
        '
}

# Records the running release in .env, so docker compose commands run by
# hand use its images
save_image_tag() {
    if grep -q '^IMAGE_TAG=' .env; then
        sed -i "s/^IMAGE_TAG=.*/IMAGE_TAG=$1/" .env
    else
        printf '\n# The running release, set by each deploy\nIMAGE_TAG=%s\n' "$1" >> .env
    fi
}

# Keeps the images of the three newest releases, for a rollback, and removes
# older ones and leftover build layers. Three rather than two, so a build
# that failed its checks never pushes out the last release that ran.
remove_old_images() {
    local app_name repository tag
    app_name="$(env_value APP_NAME)"

    for repository in "$app_name-app" "$app_name-nginx"; do
        # Listed newest first
        docker image ls "$repository" --format '{{.Tag}}' | tail -n +4 | while read -r tag; do
            docker image rm "$repository:$tag" > /dev/null || true
        done
    done

    docker image prune --force > /dev/null
}

deploy() {
    local branch
    branch="$(env_value DEPLOY_BRANCH)"

    if [[ -z "$branch" ]]; then
        log "DEPLOY_BRANCH is not set in .env; refusing to deploy"
        exit 1
    fi

    # The development compose file mounts the code from the host and adds the
    # Stripe CLI; servers run only the images
    if [[ "$(env_value COMPOSE_FILE)" == *docker-compose.dev.yml* ]]; then
        log "COMPOSE_FILE includes docker-compose.dev.yml, which is for local development only; refusing to deploy"
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

    # Compared after each checkout, to restart with a changed script
    SCRIPT_HASH="$(git hash-object deploy/deploy.sh)"

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
