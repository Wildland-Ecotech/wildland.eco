#!/usr/bin/env bash
#
# Sets up a Wildland Ecotech staging or production server.
#
# Run as root on a Debian or Ubuntu server whose domain already points at it:
#
#   curl -fsSLO https://raw.githubusercontent.com/Wildland-Ecotech/wildland.eco/main/deploy/setup.sh
#   sudo bash setup.sh
#
# It installs Docker, creates the deploy user, clones the repository to
# /srv/wildland.eco, writes both .env files (generating every secret and
# prompting only for what it cannot generate), runs the first deploy, and
# installs the systemd units that deploy on each push. At the end it prints
# the GitHub webhook settings to add.
#
# Safe to re-run: existing .env files are kept, and the remaining steps are
# repeated. See docs/07-deployment.md.

set -euo pipefail

REPO_URL="https://github.com/Wildland-Ecotech/wildland.eco.git"
INSTALL_DIR="/srv/wildland.eco"
DEPLOY_USER="deploy"

SMTP_HOST="send.ahasend.com"
SMTP_PORT="587"

# --- Output and prompts -------------------------------------------------------

info() {
    echo
    echo "==> $*"
}

fail() {
    echo "Error: $*" >&2
    exit 1
}

# Prompts are read from the terminal, so they work even if this script's
# standard input is redirected. Prompt text goes to stderr; the answer is
# printed to stdout for the caller to capture.
ask() {
    local prompt="$1" default="${2:-}" reply

    if [[ -n "$default" ]]; then
        read -r -p "$prompt [$default]: " reply < /dev/tty
        echo "${reply:-$default}"
    else
        read -r -p "$prompt: " reply < /dev/tty
        echo "$reply"
    fi
}

ask_secret() {
    local reply
    read -r -s -p "$1: " reply < /dev/tty
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

# --- Setup steps --------------------------------------------------------------

check_system() {
    [[ "$(id -u)" -eq 0 ]] || fail "run this script as root, for example with sudo"

    # shellcheck source=/dev/null
    source /etc/os-release
    OS_ID="$ID"
    OS_CODENAME="$VERSION_CODENAME"

    [[ "$OS_ID" == "debian" || "$OS_ID" == "ubuntu" ]] || fail "only Debian and Ubuntu are supported (found $OS_ID)"
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

# The deploy user owns the clone and runs deploys. Membership in the docker
# group is equivalent to root access, so it gets no password or login shell.
create_deploy_user() {
    if id "$DEPLOY_USER" > /dev/null 2>&1; then
        info "User $DEPLOY_USER already exists"
    else
        info "Creating user $DEPLOY_USER"
        useradd --system --create-home --shell /usr/sbin/nologin "$DEPLOY_USER"
    fi

    usermod -aG docker "$DEPLOY_USER"
}

as_deploy() {
    runuser -u "$DEPLOY_USER" -- "$@"
}

clone_repository() {
    local branch="$1"

    if [[ -d "$INSTALL_DIR/.git" ]]; then
        info "Repository already cloned at $INSTALL_DIR"
    else
        if [[ -e "$INSTALL_DIR" && -n "$(ls -A "$INSTALL_DIR")" ]]; then
            fail "$INSTALL_DIR exists and is not empty"
        fi

        info "Cloning the $branch branch to $INSTALL_DIR"
        mkdir -p "$INSTALL_DIR"
        chown "$DEPLOY_USER:$DEPLOY_USER" "$INSTALL_DIR"
        as_deploy git clone --quiet --branch "$branch" "$REPO_URL" "$INSTALL_DIR"
    fi

    as_deploy mkdir -p "$INSTALL_DIR/deploy/trigger"
}

write_env_files() {
    local site="$1" domain="$2" branch="$3" smtp_username="$4" smtp_password="$5"
    local root_env="$INSTALL_DIR/.env" laravel_env="$INSTALL_DIR/laravel/.env"

    info "Writing .env files with newly generated secrets"

    # Hex-encoded secrets need no quoting in either .env file
    local db_password webhook_secret app_key
    db_password="$(openssl rand -hex 24)"
    webhook_secret="$(openssl rand -hex 32)"
    app_key="base64:$(openssl rand -base64 32)"

    as_deploy cp "$INSTALL_DIR/.env.example" "$root_env"
    set_env "$root_env" UID "$(id -u "$DEPLOY_USER")"
    set_env "$root_env" GID "$(id -g "$DEPLOY_USER")"
    set_env "$root_env" DB_PASSWORD "$db_password"
    set_env "$root_env" COMPOSE_PROFILES server
    set_env "$root_env" CADDY_SITE "$site"
    set_env "$root_env" DEPLOY_BRANCH "$branch"
    set_env "$root_env" WEBHOOK_SECRET "$webhook_secret"

    as_deploy cp "$INSTALL_DIR/laravel/.env.example" "$laravel_env"
    set_env "$laravel_env" APP_ENV production
    set_env "$laravel_env" APP_DEBUG false
    set_env "$laravel_env" APP_URL "https://$domain"
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

    # Both files hold secrets: readable only by the deploy user
    chmod 600 "$root_env" "$laravel_env"
}

first_deploy() {
    info "Running the first deploy (builds containers and obtains the HTTPS certificate)"
    as_deploy "$INSTALL_DIR/deploy/deploy.sh"
}

# The units are copied rather than linked, so a deploy can never change the
# host's services. Re-running this script installs updated copies.
install_systemd_units() {
    info "Installing systemd units for automatic deploys"
    cp "$INSTALL_DIR/deploy/systemd/wildland-deploy.path" "$INSTALL_DIR/deploy/systemd/wildland-deploy.service" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now wildland-deploy.path > /dev/null
}

print_summary() {
    local domain="$1" root_env="$INSTALL_DIR/.env"

    cat << EOF

==============================================================================
Setup complete: https://$domain

Add a webhook in GitHub (repository Settings → Webhooks → Add webhook):

  Payload URL:   https://$domain/hooks/deploy
  Content type:  application/json
  Secret:        $(env_value "$root_env" WEBHOOK_SECRET)
  Events:        Just the push event

GitHub's first test ping gets a 403 response. That is expected: only pushes
to the $(env_value "$root_env" DEPLOY_BRANCH) branch start a deploy.

Watch deploys with:  journalctl -u wildland-deploy -f
==============================================================================
EOF
}

# --- Main ---------------------------------------------------------------------

main() {
    check_system

    local site domain branch smtp_username="" smtp_password=""
    local configure=true

    if [[ -f "$INSTALL_DIR/.env" ]]; then
        configure=false
        site="$(env_value "$INSTALL_DIR/.env" CADDY_SITE)"
        branch="$(env_value "$INSTALL_DIR/.env" DEPLOY_BRANCH)"
        echo "Existing configuration found in $INSTALL_DIR/.env; keeping it."
    else
        echo "Wildland Ecotech server setup"
        echo

        site="$(ask "Environment (staging or production)" "staging")"
        [[ "$site" == "staging" || "$site" == "production" ]] || fail "environment must be staging or production"

        local default_branch="main"
        [[ "$site" == "production" ]] && default_branch="release"
        branch="$(ask "Branch to deploy" "$default_branch")"

        echo
        echo "AhaSend SMTP credentials for sending email (leave the username empty to skip;"
        echo "email is then written to the Laravel log instead)."
        smtp_username="$(ask "SMTP username")"
        if [[ -n "$smtp_username" ]]; then
            smtp_password="$(ask_secret "SMTP password")"
        fi

        [[ "$smtp_username$smtp_password" != *"'"* ]] || fail "SMTP credentials containing a single quote (') are not supported"
    fi

    domain="stage.wildland.eco"
    [[ "$site" == "production" ]] && domain="prod.wildland.eco"

    install_packages
    install_docker
    create_deploy_user
    clone_repository "$branch"

    if [[ "$configure" == true ]]; then
        write_env_files "$site" "$domain" "$branch" "$smtp_username" "$smtp_password"
    fi

    first_deploy
    install_systemd_units
    print_summary "$domain"
}

main "$@"
