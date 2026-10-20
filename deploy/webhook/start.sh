#!/bin/sh
# Starts the webhook listener, refusing to run without a signing secret:
# an empty secret would let anyone forge a valid signature.

set -eu

if [ -z "${WEBHOOK_SECRET:-}" ] || [ -z "${DEPLOY_BRANCH:-}" ]; then
    echo "WEBHOOK_SECRET and DEPLOY_BRANCH must be set in the root .env" >&2
    exit 1
fi

exec webhook -hooks /etc/webhook/hooks.json -template -port 9000 -verbose
