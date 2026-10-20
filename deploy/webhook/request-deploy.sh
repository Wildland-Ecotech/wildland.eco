#!/bin/sh
# Run by the webhook listener for every request signed with this server's
# secret, with the pushed ref (refs/heads/<branch>) as the only argument. Its
# output is the response GitHub shows under Recent Deliveries.
#
# Pushes to other branches and GitHub's ping event (which has no ref) succeed
# without deploying, so a failed delivery in GitHub always means a real
# problem. Writing the trigger file starts the wildland-deploy systemd service
# on the host.

set -eu

ref="${1:-}"

if [ "$ref" != "refs/heads/$DEPLOY_BRANCH" ]; then
    printf 'Ignored %s: this server deploys %s\n' "${ref:-a request without a ref}" "$DEPLOY_BRANCH"
    exit 0
fi

date -u > /trigger/deploy-requested
printf 'Deploy of %s requested\n' "$DEPLOY_BRANCH"
