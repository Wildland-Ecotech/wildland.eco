#!/bin/sh
# Run by the webhook listener for a verified push to this server's branch.
# Writing the trigger file starts the wildland-deploy systemd service on the
# host. The request's contents are never used.

date -u > /trigger/deploy-requested
