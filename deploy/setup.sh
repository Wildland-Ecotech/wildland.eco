#!/usr/bin/env bash
#
# Sets up a Wildland Ecotech staging or production server.
#
# Run from a clone of the repository, as root, on a Debian or Ubuntu server
# whose domains already point at it:
#
#   sudo apt install git
#   git clone https://github.com/Wildland-Ecotech/wildland.eco.git
#   cd wildland.eco
#   sudo deploy/setup.sh
#
# The clone can live in any directory. Deploys and containers run as the
# clone's owner, and setup adds that user to the docker group. A clone owned
# by root is handed to a dedicated `deploy` user instead, because the
# containers must not run as root.
#
# It installs Docker, writes both .env files (generating every secret and
# prompting only for what it cannot generate), runs the first deploy, and
# installs the systemd units that deploy on each push. At the end it prints
# the GitHub webhook settings to add.
#
# Safe to re-run: existing .env files are kept, and the remaining steps are
# repeated. See docs/07-deployment.md.

set -euo pipefail

SMTP_HOST="send.ahasend.com"
SMTP_PORT="587"

# All work happens in functions called from the last line, so the first
# deploy can update this file without affecting the run in progress.

# --- Output and prompts -------------------------------------------------------

info() {
    echo
    echo "==> $*"
}

fail() {
    echo "Error: $*" >&2
    exit 1
}

# Prompt text goes to stderr; the answer is printed to stdout for the caller
# to capture.
ask() {
    local prompt="$1" default="${2:-}" reply

    if [[ -n "$default" ]]; then
        read -r -p "$prompt [$default]: " reply
        echo "${reply:-$default}"
    else
        read -r -p "$prompt: " reply
        echo "$reply"
    fi
}

ask_secret() {
    local reply
    read -r -s -p "$1: " reply
    echo >&2
    echo "$reply"
}

# --- .env files ---------------------------------------------------------------

# Reads KEY=value from a .env file; prints nothing if the key is missing
env_value() {
    { grep -E "^$2=" "$1" || true; } | tail -n 1 | cut -d '=' -f 2-
}

# Sets KEY=VALUE in a .env file, replacing an existing or commented-out line
# for the key, or appending one. VALUE is written exactly as given. The file
# is rewritten in place so its owner and permissions are kept.
set_env() {
    local file="$1" key="$2" value="$3"

    KEY="$key" VALUE="$value" awk '
        BEGIN { key = ENVIRON["KEY"]; line = key "=" ENVIRON["VALUE"] }
        !done && $0 ~ ("^#? *" key "=") { print line; done = 1; next }
        { print }
        END { if (!done) print line }
    ' "$file" > "$file.tmp"

    cat "$file.tmp" > "$file"
    rm "$file.tmp"
}

# Single-quotes a value for laravel/.env so it is read literally, whatever
# characters it contains. Single-quoted values cannot contain a single quote;
# main() rejects those before anything is written.
quote() {
    echo "'$1'"
}

# --- Repository ---------------------------------------------------------------

# Runs a command as the user who owns the clone
as_owner() {
    if [[ "$OWNER" == "root" ]]; then
        "$@"
    else
        runuser -u "$OWNER" -- "$@"
    fi
}

# Checks that a branch exists on GitHub and contains the Laravel site and
# deploy files. Prints the reason and returns 1 if it cannot be deployed.
check_branch() {
    local branch="$1"

    if [[ -z "$(as_owner git -C "$INSTALL_DIR" ls-remote --heads origin "$branch")" ]]; then
        echo "The $branch branch does not exist on GitHub yet." >&2
        return 1
    fi

    as_owner git -C "$INSTALL_DIR" fetch --quiet origin "$branch"

    if ! as_owner git -C "$INSTALL_DIR" cat-file -e "origin/$branch:deploy/deploy.sh" 2> /dev/null; then
        echo "The $branch branch does not contain the Laravel site and its deploy files yet." >&2
        return 1
    fi
}

# --- Setup steps --------------------------------------------------------------

check_system() {
    [[ "$(id -u)" -eq 0 ]] || fail "run this script as root, for example with sudo"

    # shellcheck source=/dev/null
    source /etc/os-release
    OS_ID="$ID"
    OS_CODENAME="$VERSION_CODENAME"

    [[ "$OS_ID" == "debian" || "$OS_ID" == "ubuntu" ]] || fail "only Debian and Ubuntu are supported (found $OS_ID)"

    INSTALL_DIR="$(cd "$(dirname "$0")/.." && pwd)"
    OWNER="$(stat -c '%U' "$INSTALL_DIR")"

    [[ -d "$INSTALL_DIR/.git" ]] || fail "run this script from a clone of the repository"

    # The path is written into the systemd units, so keep it to plain characters
    [[ "$INSTALL_DIR" =~ ^[A-Za-z0-9._/-]+$ ]] || fail "the repository path may only contain letters, numbers, '.', '_', '-', and '/' ($INSTALL_DIR)"
}

install_packages() {
    info "Installing required packages"
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl git openssl > /dev/null
}

# Installs Docker Engine and the Compose plugin from Docker's apt repository
install_docker() {
    if command -v docker > /dev/null && docker compose version > /dev/null 2>&1; then
        info "Docker is already installed"
        return
    fi

    info "Installing Docker"
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL "https://download.docker.com/linux/$OS_ID/gpg" -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$OS_ID $OS_CODENAME stable" \
        > /etc/apt/sources.list.d/docker.list

    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin > /dev/null
    systemctl enable --now docker
}

# Containers run as the clone's owner (UID and GID in the root .env). They
# must not run as root: PHP-FPM started as root hands requests to workers
# running as www-data, which cannot write to the root-owned storage
# directory. A clone owned by root is therefore given to a dedicated deploy
# user, which runs deploys and has no password or login shell.
ensure_non_root_owner() {
    if [[ "$OWNER" != "root" ]]; then
        return 0
    fi

    info "The clone is owned by root; handing it to the deploy user"

    if ! id deploy > /dev/null 2>&1; then
        useradd --system --create-home --shell /usr/sbin/nologin deploy
    fi

    chown -R deploy:deploy "$INSTALL_DIR"
    OWNER="deploy"
}

# Keeps the container user IDs in an existing root .env in step with the
# clone's owner, which may have changed since the file was written
sync_owner_ids() {
    local root_env="$INSTALL_DIR/.env"

    set_env "$root_env" UID "$(id -u "$OWNER")"
    set_env "$root_env" GID "$(id -g "$OWNER")"

    local file
    for file in "$root_env" "$INSTALL_DIR/laravel/.env"; do
        if [[ -f "$file" ]]; then
            chown "$OWNER:$OWNER" "$file"
        fi
    done
}

# Deploys run as the clone's owner, who needs Docker access. Membership in
# the docker group is equivalent to root access.
grant_docker_access() {
    if [[ "$OWNER" != "root" ]]; then
        info "Adding $OWNER to the docker group"
        usermod -aG docker "$OWNER"
    fi
}

write_env_files() {
    local site="$1" site_url="$2" branch="$3" smtp_username="$4" smtp_password="$5"
    local root_env="$INSTALL_DIR/.env" laravel_env="$INSTALL_DIR/laravel/.env"

    info "Writing .env files with newly generated secrets"

    # Hex-encoded secrets need no quoting in either .env file
    local db_password webhook_secret app_key
    db_password="$(openssl rand -hex 24)"
    webhook_secret="$(openssl rand -hex 32)"
    app_key="base64:$(openssl rand -base64 32)"

    as_owner cp "$INSTALL_DIR/.env.example" "$root_env"
    set_env "$root_env" UID "$(id -u "$OWNER")"
    set_env "$root_env" GID "$(id -g "$OWNER")"
    set_env "$root_env" DB_PASSWORD "$db_password"
    set_env "$root_env" COMPOSE_PROFILES server
    set_env "$root_env" CADDY_SITE "$site"
    set_env "$root_env" DEPLOY_BRANCH "$branch"
    set_env "$root_env" WEBHOOK_SECRET "$webhook_secret"

    as_owner cp "$INSTALL_DIR/laravel/.env.example" "$laravel_env"
    set_env "$laravel_env" APP_ENV production
    set_env "$laravel_env" APP_DEBUG false
    set_env "$laravel_env" APP_URL "$site_url"
    set_env "$laravel_env" APP_KEY "$app_key"
    set_env "$laravel_env" DB_PASSWORD "$db_password"

    if [[ -n "$smtp_username" ]]; then
        set_env "$laravel_env" MAIL_MAILER smtp
        set_env "$laravel_env" MAIL_SCHEME smtp
        set_env "$laravel_env" MAIL_HOST "$SMTP_HOST"
        set_env "$laravel_env" MAIL_PORT "$SMTP_PORT"
        set_env "$laravel_env" MAIL_USERNAME "$(quote "$smtp_username")"
        set_env "$laravel_env" MAIL_PASSWORD "$(quote "$smtp_password")"
    fi

    # Both files hold secrets: readable only by the clone's owner
    chmod 600 "$root_env" "$laravel_env"
}

first_deploy() {
    info "Running the first deploy (builds containers and obtains the HTTPS certificate)"
    as_owner mkdir -p "$INSTALL_DIR/deploy/trigger"
    as_owner "$INSTALL_DIR/deploy/deploy.sh"
}

# Installs the systemd units with this clone's path and owner filled in. They
# are copied rather than linked, so a deploy can never change the host's
# services. Re-running this script installs updated copies.
install_systemd_units() {
    info "Installing systemd units for automatic deploys"

    local unit
    for unit in wildland-deploy.path wildland-deploy.service; do
        INSTALL_DIR="$INSTALL_DIR" OWNER="$OWNER" awk '{
            gsub(/@INSTALL_DIR@/, ENVIRON["INSTALL_DIR"])
            gsub(/@DEPLOY_USER@/, ENVIRON["OWNER"])
            print
        }' "$INSTALL_DIR/deploy/systemd/$unit" > "/etc/systemd/system/$unit"
    done

    systemctl daemon-reload
    systemctl enable --now wildland-deploy.path > /dev/null
}

print_summary() {
    local hostname="$1" site_url="$2" root_env="$INSTALL_DIR/.env"

    cat << EOF

==============================================================================
Setup complete: $site_url

Add a webhook in GitHub (repository Settings → Webhooks → Add webhook):

  Payload URL:   https://$hostname/hooks/deploy
  Content type:  application/json
  Secret:        $(env_value "$root_env" WEBHOOK_SECRET)
  Events:        Just the push event

GitHub's first test ping should succeed with an "Ignored" response; only
pushes to the $(env_value "$root_env" DEPLOY_BRANCH) branch start a deploy.

Watch deploys with:  journalctl -u wildland-deploy -f
==============================================================================
EOF
}

# --- Main ---------------------------------------------------------------------

main() {
    echo "Wildland Ecotech server setup"

    check_system
    ensure_non_root_owner

    local site hostname site_url branch smtp_username="" smtp_password=""
    local configure=true

    echo
    echo "Repository: $INSTALL_DIR (deploys run as $OWNER)"

    install_packages

    if [[ -f "$INSTALL_DIR/.env" ]]; then
        configure=false
        site="$(env_value "$INSTALL_DIR/.env" CADDY_SITE)"
        branch="$(env_value "$INSTALL_DIR/.env" DEPLOY_BRANCH)"
        echo
        echo "Existing configuration found in $INSTALL_DIR/.env; keeping it."
        check_branch "$branch" || fail "change DEPLOY_BRANCH in $INSTALL_DIR/.env to a branch that can be deployed"
        sync_owner_ids
    else
        echo

        site="$(ask "Environment (staging or production)" "staging")"
        [[ "$site" == "staging" || "$site" == "production" ]] || fail "environment must be staging or production"

        local default_branch="main"
        [[ "$site" == "production" ]] && default_branch="release"

        # Ask until the branch can be deployed
        while true; do
            branch="$(ask "Branch to deploy" "$default_branch")"
            check_branch "$branch" && break
            echo "Choose another branch." >&2
        done

        echo
        echo "AhaSend SMTP credentials for sending email (leave the username empty to skip;"
        echo "email is then written to the Laravel log instead)."
        smtp_username="$(ask "SMTP username")"
        if [[ -n "$smtp_username" ]]; then
            smtp_password="$(ask_secret "SMTP password")"
        fi

        [[ "$smtp_username$smtp_password" != *"'"* ]] || fail "SMTP credentials containing a single quote (') are not supported"
    fi

    # The server's own hostname receives the webhook. Production's site is
    # served at wildland.eco, which can point at another server during a move.
    hostname="stage.wildland.eco"
    site_url="https://stage.wildland.eco"
    if [[ "$site" == "production" ]]; then
        hostname="prod.wildland.eco"
        site_url="https://wildland.eco"
    fi

    install_docker
    grant_docker_access

    if [[ "$configure" == true ]]; then
        write_env_files "$site" "$site_url" "$branch" "$smtp_username" "$smtp_password"
    fi

    first_deploy
    install_systemd_units
    print_summary "$hostname" "$site_url"
}

main "$@"
exit
